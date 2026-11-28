<?php

use App\Actions\Vault\CreateCredential;
use App\Actions\Vault\DeleteProject;
use App\Actions\Vault\MoveCredential;
use App\Actions\Vault\UpdateCredential;
use App\Enums\AccessResult;
use App\Enums\CredentialType;
use App\Exceptions\Vault\HostConflictException;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function createProjectCredential(Project $project, string $host, string $secret = 'shared-secret'): Credential
{
    return app(CreateCredential::class)->handle($project, [
        'name' => 'Magento',
        'type' => CredentialType::HttpBasic,
        'host' => $host,
        'username' => 'public-key',
    ], $secret);
}

function fetchComposerAuth(string $plainToken, string $slug): TestResponse
{
    return test()->flushHeaders()
        ->withHeaders(['Authorization' => 'Bearer '.$plainToken])
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->getJson("/api/v1/projects/{$slug}/composer-auth");
}

it('refuses a second credential for the same host in one project', function () {
    $project = Project::factory()->create();
    createProjectCredential($project, 'repo.magento.com');

    expect(fn () => createProjectCredential($project, 'repo.magento.com'))->toThrow(HostConflictException::class);

    expect(Credential::query()->count())->toBe(1);
});

it('also refuses a host that differs only in case', function () {
    $project = Project::factory()->create();
    createProjectCredential($project, 'repo.magento.com');

    expect(fn () => createProjectCredential($project, 'Repo.Magento.com'))->toThrow(HostConflictException::class);
});

it('refuses a host that another credential of the project holds when updating', function () {
    $project = Project::factory()->create();
    createProjectCredential($project, 'repo.magento.com');
    $credential = createProjectCredential($project, 'github.com');

    expect(fn () => app(UpdateCredential::class)->handle($credential, ['host' => 'repo.magento.com']))
        ->toThrow(HostConflictException::class);

    expect($credential->fresh()->host)->toBe('github.com');
});

it('backs the host rule with a unique index in the database', function () {
    $project = Project::factory()->create();
    Credential::factory()->for($project)->forHost('repo.magento.com')->create();

    Credential::factory()->for($project)->forHost('repo.magento.com')->create();
})->throws(UniqueConstraintViolationException::class);

it('allows the same host and the same secret in two projects without a reference between them', function () {
    [$first, $second] = Project::factory()->count(2)->create();

    $inFirst = createProjectCredential($first, 'repo.magento.com');
    $inSecond = createProjectCredential($second, 'repo.magento.com');

    expect(Credential::query()->count())->toBe(2)
        ->and($inFirst->revealSecret())->toBe('shared-secret')
        ->and($inSecond->revealSecret())->toBe('shared-secret')
        ->and($inFirst->secret_ciphertext)->not->toBe($inSecond->secret_ciphertext)
        ->and($inFirst->project_id)->toBe($first->id)
        ->and($inSecond->project_id)->toBe($second->id);

    $entries = AuditEntry::query()->where('event', 'credential_created')->orderBy('sequence')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->properties['project_id'])->toBe($first->id)
        ->and($entries[1]->properties['project_id'])->toBe($second->id)
        ->and(json_encode($entries[0]->properties))->not->toContain($second->id)->not->toContain($inSecond->id)
        ->and(json_encode($entries[1]->properties))->not->toContain($first->id)->not->toContain($inFirst->id);
});

it('moves a credential with its secret readable and its ciphertext unchanged and audits the move', function () {
    [$from, $to] = Project::factory()->count(2)->create();
    $credential = createProjectCredential($from, 'repo.magento.com', 'move-me');
    $before = $credential->fresh()->only(['secret_ciphertext', 'data_key_ciphertext', 'key_version', 'secret_changed_at']);

    app(MoveCredential::class)->handle($credential, $to);

    $moved = Credential::query()->find($credential->id);
    $entry = AuditEntry::query()->where('event', 'credential_moved')->sole();

    expect($moved->project_id)->toBe($to->id)
        ->and($moved->revealSecret())->toBe('move-me')
        ->and($moved->only(['secret_ciphertext', 'data_key_ciphertext', 'key_version', 'secret_changed_at']))->toEqual($before)
        ->and($entry->subject_id)->toBe($credential->id)
        ->and($entry->properties->toArray())->toEqual(['from_project_id' => $from->id, 'to_project_id' => $to->id])
        ->and($from->credentials()->count())->toBe(0)
        ->and($to->credentials()->count())->toBe(1);
});

it('rejects a move to a project that holds the host already and changes nothing', function () {
    [$from, $to] = Project::factory()->count(2)->create();
    $credential = createProjectCredential($from, 'repo.magento.com');
    createProjectCredential($to, 'repo.magento.com');
    $auditCount = AuditEntry::query()->count();

    expect(fn () => app(MoveCredential::class)->handle($credential, $to))->toThrow(HostConflictException::class);

    expect($credential->fresh()->project_id)->toBe($from->id)
        ->and(AuditEntry::query()->count())->toBe($auditCount);
});

it('does nothing when the target is the current project', function () {
    $project = Project::factory()->create();
    $credential = createProjectCredential($project, 'repo.magento.com');
    $auditCount = AuditEntry::query()->count();

    app(MoveCredential::class)->handle($credential, $project);

    expect($credential->fresh()->project_id)->toBe($project->id)
        ->and(AuditEntry::query()->count())->toBe($auditCount);
});

it('gives a token the same 404 for another project as for an unknown project and logs project_denied', function () {
    $own = Project::factory()->create(['slug' => 'own']);
    $other = Project::factory()->create(['slug' => 'other']);
    Credential::factory()->for($other)->withSecret('other-secret')->create();
    [$token, $plain] = tokenWithPlain(AccessToken::factory()->for($own));

    $unknown = fetchComposerAuth($plain, 'does-not-exist');
    $denied = fetchComposerAuth($plain, 'other');

    assertApiError($unknown, 404, 'not_found');
    assertApiError($denied, 404, 'not_found');

    expect($denied->getContent())->toBe($unknown->getContent())
        ->and($denied->getContent())->not->toContain('other-secret');

    $logs = AccessLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->result)->toBe(AccessResult::UnknownProject)
        ->and($logs[1]->result)->toBe(AccessResult::ProjectDenied)
        ->and($logs[1]->requested_slug)->toBe('other')
        ->and($logs[1]->project_id)->toBeNull()
        ->and($logs[1]->access_token_id)->toBe($token->id);
});

it('lists only the credentials of the project in the API response', function () {
    [$mine, $other] = Project::factory()->count(2)->sequence(['slug' => 'mine'], ['slug' => 'other'])->create();
    Credential::factory()->for($mine)->ofType(CredentialType::Bearer)->forHost('mine.example.com')->withSecret('mine-secret')->create();
    Credential::factory()->for($other)->ofType(CredentialType::Bearer)->forHost('other.example.com')->withSecret('other-secret')->create();
    [, $plain] = tokenWithPlain(AccessToken::factory()->for($mine));

    $response = fetchComposerAuth($plain, 'mine');

    $response->assertOk();
    expect($response->json())->toBe(['bearer' => ['mine.example.com' => 'mine-secret']])
        ->and($response->getContent())->not->toContain('other');
});

it('deletes a project with its credentials and tokens, audits each before the project and keeps other projects', function () {
    [$project, $other] = Project::factory()->count(2)->create();
    $credentials = [
        Credential::factory()->for($project)->forHost('one.example.com')->create(['name' => 'One']),
        Credential::factory()->for($project)->forHost('two.example.com')->create(['name' => 'Two']),
    ];
    $tokens = AccessToken::factory()->for($project)->count(2)->create();
    $keptCredential = Credential::factory()->for($other)->create();
    $keptToken = AccessToken::factory()->for($other)->create();

    app(DeleteProject::class)->handle($project);

    expect(Project::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Credential::query()->pluck('id')->all())->toBe([$keptCredential->id])
        ->and(AccessToken::query()->pluck('id')->all())->toBe([$keptToken->id]);

    $entries = AuditEntry::query()->orderBy('sequence')->get();

    expect($entries->pluck('event')->all())->toBe(['credential_deleted', 'credential_deleted', 'token_deleted', 'token_deleted', 'project_deleted'])
        ->and($entries->take(2)->pluck('subject_id')->sort()->values()->all())->toBe(collect($credentials)->pluck('id')->sort()->values()->all())
        ->and($entries->slice(2, 2)->pluck('subject_id')->sort()->values()->all())->toBe($tokens->pluck('id')->sort()->values()->all())
        ->and($entries->take(4)->every(fn (AuditEntry $entry) => $entry->properties['with_project'] === true && $entry->properties['project_id'] === $project->id))->toBeTrue()
        ->and($entries->last()->subject_id)->toBe($project->id);
});
