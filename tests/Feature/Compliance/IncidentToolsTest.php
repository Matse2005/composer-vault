<?php

use App\Actions\Vault\ExportIncidentTimeline;
use App\Actions\Vault\RevokeTokensInBulk;
use App\Actions\Vault\SaveIncident;
use App\Actions\Vault\SetLockdown;
use App\Actions\Vault\SignOutAdmins;
use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->withTwoFactor()->create();
});

it('revokes every token of one project and no other', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $mine = AccessToken::factory()->for($project)->count(2)->create();
    $theirs = AccessToken::factory()->for($other)->create();
    AccessToken::factory()->for($project)->revoked()->create();

    $count = app(RevokeTokensInBulk::class)->handle($this->owner, $project);

    expect($count)->toBe(2)
        ->and($mine->every(fn ($token) => $token->fresh()->isRevoked()))->toBeTrue()
        ->and($theirs->fresh()->isRevoked())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'tokens_revoked_in_bulk')->sole()->properties->toArray())->toEqual(['count' => 2, 'scope' => 'project']);
});

it('revokes every token', function () {
    AccessToken::factory()->count(3)->create();

    expect(app(RevokeTokensInBulk::class)->handle($this->owner))->toBe(3)
        ->and(AccessToken::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'tokens_revoked_in_bulk')->sole()->properties['scope'])->toBe('all');
});

it('signs out every admin except the actor', function () {
    config(['session.driver' => 'database']);
    $other = User::factory()->admin()->create();
    DB::table('sessions')->insert([
        ['id' => 'mine', 'user_id' => $this->owner->id, 'ip_address' => '1.1.1.1', 'user_agent' => 't', 'payload' => '', 'last_activity' => time()],
        ['id' => 'theirs-1', 'user_id' => $other->id, 'ip_address' => '1.1.1.1', 'user_agent' => 't', 'payload' => '', 'last_activity' => time()],
        ['id' => 'theirs-2', 'user_id' => $other->id, 'ip_address' => '1.1.1.1', 'user_agent' => 't', 'payload' => '', 'last_activity' => time()],
        ['id' => 'guest', 'user_id' => null, 'ip_address' => '1.1.1.1', 'user_agent' => 't', 'payload' => '', 'last_activity' => time()],
    ]);
    $token = $other->remember_token;

    expect(app(SignOutAdmins::class)->handle($this->owner))->toBe(2)
        ->and(DB::table('sessions')->pluck('id')->sort()->values()->all())->toBe(['guest', 'mine'])
        ->and($other->fresh()->remember_token)->not->toBe($token)
        ->and(AuditEntry::query()->where('event', 'admins_signed_out')->sole()->properties['sessions'])->toBe(2);
});

it('blocks admins and auditors during a lockdown and lets owners in', function () {
    $admin = User::factory()->withTwoFactor()->admin()->create();
    $auditor = User::factory()->withTwoFactor()->auditor()->create();

    app(SetLockdown::class)->handle(true, $this->owner);

    $this->actingAs($admin)->get(route('projects.index'))->assertForbidden();
    $this->actingAs($auditor)->get(route('logs.audit'))->assertForbidden();
    $this->actingAs($this->owner)->get(route('projects.index'))->assertOk();
    $this->actingAs($admin)->post(route('logout'))->assertRedirect();

    app(SetLockdown::class)->handle(false, $this->owner);

    $this->actingAs($admin)->get(route('projects.index'))->assertOk();
    expect(AuditEntry::query()->where('event', 'lockdown_changed')->count())->toBe(2);
});

it('refuses every emergency action to other roles', function (string $role) {
    $actor = User::factory()->create(['role' => AdminRole::from($role)]);

    foreach ([
        fn () => app(RevokeTokensInBulk::class)->handle($actor),
        fn () => app(SignOutAdmins::class)->handle($actor),
        fn () => app(SetLockdown::class)->handle(true, $actor),
    ] as $run) {
        expect($run)->toThrow(AuthorizationException::class);
    }

    $this->actingAs($actor->forceFill(['two_factor_confirmed_at' => now()]));
    $this->get(route('incidents'))->assertForbidden();
})->with(['admin', 'auditor']);

it('needs the typed word for each emergency action from the page', function () {
    AccessToken::factory()->count(2)->create();
    $this->actingAs($this->owner);

    Livewire::test('pages::incidents')->set('confirmation', 'revoke-all')->call('emergency', 'revoke-all')->assertHasErrors('confirmation');
    expect(AccessToken::query()->whereNull('revoked_at')->count())->toBe(2);

    Livewire::test('pages::incidents')->set('confirmation', 'REVOKE-ALL')->call('emergency', 'revoke-all')->assertHasNoErrors()->assertSee('2 token(s) revoked');
    expect(AccessToken::query()->whereNull('revoked_at')->count())->toBe(0);
});

it('runs the other emergency actions from the page with their word', function () {
    $project = Project::factory()->create();
    $token = AccessToken::factory()->for($project)->create();
    $this->actingAs($this->owner);

    Livewire::test('pages::incidents')->set('projectId', $project->id)->set('confirmation', 'REVOKE')->call('emergency', 'revoke-project');
    expect($token->fresh()->isRevoked())->toBeTrue();

    Livewire::test('pages::incidents')->set('confirmation', 'LOCKDOWN')->call('emergency', 'lockdown-on');
    expect(SetLockdown::isOn())->toBeTrue();

    Livewire::test('pages::incidents')->assertSee('Lockdown is on')->set('confirmation', 'UNLOCK')->call('emergency', 'lockdown-off');
    expect(SetLockdown::isOn())->toBeFalse();

    Livewire::test('pages::incidents')->set('confirmation', 'SIGN-OUT')->call('emergency', 'sign-out')->assertHasNoErrors();
});

it('creates and closes an incident record and audits without the notes', function () {
    $incident = app(SaveIncident::class)->handle(null, 'Leaked token', now()->subHours(2), null, 'Contact Jane Doe about it', $this->owner);

    expect($incident->created_by)->toBe($this->owner->id)->and($incident->closed_at)->toBeNull();

    app(SaveIncident::class)->handle($incident, 'Leaked token', now()->subHours(2), now(), 'Contact Jane Doe about it', $this->owner);

    expect($incident->fresh()->closed_at)->not->toBeNull()
        ->and(AuditEntry::query()->whereIn('event', ['incident_created', 'incident_updated'])->count())->toBe(2)
        ->and(json_encode(AuditEntry::query()->get()->map->properties))->not->toContain('Jane Doe')->not->toContain('Leaked token');
});

it('shows the three reference times from the detection and says they are unverified', function () {
    $incident = Incident::factory()->create(['detected_at' => '2026-11-01 10:00:00']);
    $this->actingAs($this->owner);

    expect(collect($incident->referenceTimes())->map->format('Y-m-d H:i')->all())->toBe([
        'After 24 hours' => '2026-11-02 10:00',
        'After 72 hours' => '2026-11-04 10:00',
        'After one month' => '2026-12-01 10:00',
    ]);

    Livewire::test('pages::incidents')->call('select', $incident->id)
        ->assertSee('2026-11-02 10:00')->assertSee('2026-11-04 10:00')->assertSee('2026-12-01 10:00')
        ->assertSee('not checked against the text of the directive');
});

it('saves an incident from the page and refuses a close before the detection', function () {
    $this->actingAs($this->owner);

    Livewire::test('pages::incidents')->set('title', 'Odd logins')->set('detectedAt', '2026-11-27T10:00')->call('saveIncident')->assertHasNoErrors();
    expect(Incident::query()->sole()->title)->toBe('Odd logins');

    Livewire::test('pages::incidents')->set('title', 'Odd')->set('detectedAt', '2026-11-27T10:00')->set('closedAt', '2026-11-26T10:00')->call('saveIncident')->assertHasErrors('closedAt');
});

it('exports only the rows inside the window and only the stream fields', function () {
    $this->travelTo(now()->subDays(3));
    app(AuditLogger::class)->record(AuditAction::ProjectCreated, Project::factory()->create(['name' => 'Before']));
    AccessLog::factory()->create(['created_at' => now()]);
    $this->travelBack();

    $incident = Incident::factory()->create(['detected_at' => now()->subHours(2), 'closed_at' => now()->addHour()]);
    $inside = app(AuditLogger::class)->record(AuditAction::ProjectUpdated, Project::factory()->create(['name' => 'Inside Secret Name']), ['notes' => 'Private note'], $this->owner);
    AccessLog::factory()->create(['ip' => '198.51.100.8', 'result' => 'unknown_token', 'user_agent' => 'curl/8 secret-agent']);

    $rows = app(ExportIncidentTimeline::class)->handle($incident, $this->owner);

    $types = collect($rows)->groupBy('type')->map->count()->all();

    expect($types)->toBe(['audit' => 1, 'access' => 1])
        ->and(collect($rows)->firstWhere('type', 'audit')['sequence'])->toBe($inside->sequence)
        ->and(array_keys(collect($rows)->firstWhere('type', 'access')))->toBe(['type', 'time', 'result', 'ip', 'token_prefix', 'project_slug'])
        ->and(json_encode($rows))->not->toContain('Inside Secret Name')->not->toContain('Private note')->not->toContain('secret-agent')
        ->and(AuditEntry::query()->where('event', 'incident_timeline_exported')->sole()->properties['rows'])->toBe(2);
});

it('downloads the timeline as json and csv and refuses other formats and roles', function () {
    $incident = Incident::factory()->create();
    $this->actingAs($this->owner);

    $json = $this->get(route('incidents.timeline', [$incident, 'json']))->assertOk();
    expect($json->headers->get('Content-Disposition'))->toContain('incident-'.$incident->id.'-timeline.json');

    $csv = $this->get(route('incidents.timeline', [$incident, 'csv']))->assertOk();
    expect($csv->streamedContent())->toStartWith('type,time,event,sequence,actor_id,subject_type,subject_id,ip,result,token_prefix,project_slug');

    $this->get('/incidents/'.$incident->id.'/timeline.xml')->assertNotFound();

    $this->actingAs(User::factory()->withTwoFactor()->admin()->create())->get(route('incidents.timeline', [$incident, 'json']))->assertForbidden();
});
