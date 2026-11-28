<?php

use App\Enums\AuditAction;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Alice Admin']);
    $this->actingAs($this->admin);
});

function auditRecord(AuditAction $action, mixed $subject = null, array $changes = [], ?User $actor = null, ?string $label = null): AuditEntry
{
    return app(AuditLogger::class)->record($action, $subject, $changes, $actor, $label);
}

it('lists entries with sequence, actor, action, subject and ip, newest first', function () {
    app()->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));
    $project = Project::factory()->create(['slug' => 'webshop']);
    auditRecord(AuditAction::ProjectCreated, $project, ['name' => 'Webshop'], $this->admin);
    auditRecord(AuditAction::ProjectUpdated, $project, [], $this->admin);

    $component = Livewire::test('pages::logs.audit');

    expect($component->get('entries')->pluck('event')->all())->toBe(['project_updated', 'project_created']);

    $component->assertSee('Alice Admin')->assertSee('203.0.113.9')->assertSee('webshop')->assertSee('#2')->assertSee('#1');
});

it('shows System for an entry without a causer', function () {
    $this->app['auth']->forgetGuards();
    auditRecord(AuditAction::KeyRotated, null, ['old_version' => 1, 'new_version' => 2, 'credential_count' => 3]);
    $this->actingAs($this->admin);

    $html = Livewire::test('pages::logs.audit')->html();

    expect($html)->toMatch('/<td[^>]*>\s*System\s*<\/td>/')->toMatch('/<td[^>]*>\s*key_rotated\s*<\/td>/');
});

it('links a subject that still exists to its page', function () {
    $project = Project::factory()->create();
    $credential = Credential::factory()->create();
    $token = AccessToken::factory()->create();
    auditRecord(AuditAction::ProjectCreated, $project);
    auditRecord(AuditAction::CredentialCreated, $credential);
    auditRecord(AuditAction::TokenIssued, $token);

    Livewire::test('pages::logs.audit')
        ->assertSee(route('projects.show', $project), false)
        ->assertSee(route('credentials.edit', [$credential->project_id, $credential]), false)
        ->assertSee(route('tokens.show', [$token->project_id, $token]), false);
});

it('shows the subject label of a subject that is gone', function () {
    $project = Project::factory()->create(['slug' => 'old-shop', 'name' => 'Old shop']);
    auditRecord(AuditAction::ProjectDeleted, $project, ['name' => 'Old shop'], label: 'Old shop');
    $project->delete();

    Livewire::test('pages::logs.audit')
        ->assertSee('Old shop')
        ->assertDontSee(route('projects.show', $project), false);
});

it('shows the properties pretty-printed in an expandable row', function () {
    auditRecord(AuditAction::ProjectUpdated, Project::factory()->create(), ['name' => ['old' => 'Shop', 'new' => 'Webshop']]);

    $html = Livewire::test('pages::logs.audit')->html();

    expect($html)->toContain('x-data="{ open: false }"')
        ->toContain('x-show="open"')
        ->toContain('&quot;old&quot;: &quot;Shop&quot;')
        ->toContain('&quot;new&quot;: &quot;Webshop&quot;');
});

it('shows a masked secret as changed and never a secret value', function () {
    auditRecord(AuditAction::CredentialCreated, Credential::factory()->create(), [
        'name' => 'Magento',
        'secret' => 'plaintext-secret-marker',
        'secret_ciphertext' => 'ciphertext-marker',
    ]);

    $component = Livewire::test('pages::logs.audit')->assertSee('changed');

    foreach ([$component->html(), json_encode($component->snapshot)] as $output) {
        expect($output)->not->toContain('plaintext-secret-marker')->not->toContain('ciphertext-marker');
    }
});

it('only lists entries of the audit log', function () {
    auditRecord(AuditAction::ProjectCreated, Project::factory()->create());
    DB::table('activity_log')->insert([
        'log_name' => 'other', 'description' => 'Elsewhere', 'event' => 'not_audit', 'properties' => '[]', 'created_at' => now(),
    ]);

    Livewire::test('pages::logs.audit')->assertSee('project_created')->assertDontSee('not_audit');
});

it('filters by action, actor and subject type', function () {
    $other = User::factory()->withTwoFactor()->create(['name' => 'Bob Other']);
    auditRecord(AuditAction::ProjectCreated, Project::factory()->create(), [], $this->admin);
    auditRecord(AuditAction::CredentialCreated, Credential::factory()->create(), [], $other);
    $this->app['auth']->forgetGuards();
    auditRecord(AuditAction::KeyRotated);
    $this->actingAs($this->admin);

    $events = fn ($component) => $component->get('entries')->pluck('event')->sort()->values()->all();

    $component = Livewire::test('pages::logs.audit');
    expect($events($component))->toBe(['credential_created', 'key_rotated', 'project_created']);

    $component->set('action', 'project_created');
    expect($events($component))->toBe(['project_created']);

    $component->set('action', '')->set('actor', (string) $other->id);
    expect($events($component))->toBe(['credential_created']);

    $component->set('actor', 'system');
    expect($events($component))->toBe(['key_rotated']);

    $component->set('actor', '')->set('subjectType', 'Project');
    expect($events($component))->toBe(['project_created']);

    $component->set('subjectType', 'Credential');
    expect($events($component))->toBe(['credential_created']);
});

it('ignores filter values that are not offered', function () {
    auditRecord(AuditAction::ProjectCreated, Project::factory()->create());

    $component = Livewire::test('pages::logs.audit')->set('action', 'nonsense')->set('subjectType', 'App\\Models\\Everything');

    expect($component->get('entries'))->toHaveCount(1);
});

it('verifies an intact chain and reports the number of entries', function () {
    auditRecord(AuditAction::ProjectCreated, Project::factory()->create());
    auditRecord(AuditAction::KeyRotated);

    Livewire::test('pages::logs.audit')
        ->assertDontSee('Chain intact')
        ->call('verifyChain')
        ->assertSee('Chain intact (2 entries)');
});

it('reports a broken chain with the entry where it breaks', function () {
    foreach (range(1, 4) as $unused) {
        auditRecord(AuditAction::KeyRotated);
    }
    DB::table('activity_log')->where('sequence', 3)->update(['event' => 'project_deleted']);

    Livewire::test('pages::logs.audit')->call('verifyChain')->assertSee('Chain broken at entry 3');
});

it('verifies an empty chain', function () {
    Livewire::test('pages::logs.audit')->call('verifyChain')->assertSee('Chain intact (0 entries)');
});

it('never changes an entry', function () {
    auditRecord(AuditAction::ProjectCreated, Project::factory()->create());
    $before = DB::table('activity_log')->get()->toJson();
    $headBefore = DB::table('audit_log_head')->get()->toJson();

    Livewire::test('pages::logs.audit')->call('verifyChain')->set('action', 'project_created')->set('actor', 'system');

    expect(DB::table('activity_log')->get()->toJson())->toBe($before)
        ->and(DB::table('audit_log_head')->get()->toJson())->toBe($headBefore);
});

it('shows 25 entries a page', function () {
    foreach (range(1, 30) as $unused) {
        auditRecord(AuditAction::KeyRotated, null, ['credential_count' => $unused]);
    }

    $component = Livewire::test('pages::logs.audit');

    $component->assertSee('#30')->assertDontSee('#5')->call('gotoPage', 2)->assertSee('#5');
});

it('shows an empty state', function () {
    Livewire::test('pages::logs.audit')->assertSee('No audit entries');
});

it('renders the page', function () {
    $this->get(route('logs.audit'))->assertOk()->assertSeeLivewire('pages::logs.audit');
});
