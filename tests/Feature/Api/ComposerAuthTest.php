<?php

use App\Enums\AccessResult;
use App\Enums\CredentialType;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Project;
use Database\Factories\AccessTokenFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

function composerAuthRequest(string $plainToken, string $slug, string $ip = '203.0.113.9'): TestResponse
{
    return test()->flushHeaders()
        ->withHeaders(['Authorization' => 'Bearer '.$plainToken])
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->getJson("/api/v1/projects/{$slug}/composer-auth");
}

/**
 * Create a token that belongs to the given project.
 *
 * @return array{AccessToken, string}
 */
function tokenForProject(Project $project, ?AccessTokenFactory $factory = null): array
{
    return tokenWithPlain(($factory ?? AccessToken::factory())->for($project));
}

it('returns the credentials of the project in the contract format', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    Credential::factory()->for($project)->ofType(CredentialType::HttpBasic)->forHost('repo.magento.com')->withSecret('private-key')->create(['username' => 'public-key']);
    Credential::factory()->for($project)->ofType(CredentialType::Bearer)->forHost('packages.example.com')->withSecret('bearer-token')->create();
    Credential::factory()->for($project)->ofType(CredentialType::GithubOauth)->forHost('github.com')->withSecret('github-token')->create();
    Credential::factory()->for($project)->ofType(CredentialType::GitlabToken)->forHost('gitlab.com')->withSecret('gitlab-token')->create();
    [, $plain] = tokenForProject($project);

    $response = composerAuthRequest($plain, 'shop');

    $response->assertOk();
    expect($response->json())->toBe([
        'http-basic' => ['repo.magento.com' => ['username' => 'public-key', 'password' => 'private-key']],
        'bearer' => ['packages.example.com' => 'bearer-token'],
        'github-oauth' => ['github.com' => 'github-token'],
        'gitlab-token' => ['gitlab.com' => 'gitlab-token'],
    ]);
    assertComposerAuthShape($response->json());
    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache');
});

it('returns exactly an empty object for a project without credentials', function () {
    [, $plain] = tokenForProject(Project::factory()->create(['slug' => 'empty-project']));

    $response = composerAuthRequest($plain, 'empty-project');

    $response->assertOk();
    expect($response->getContent())->toBe('{}');
});

it('gives each project its own credentials for the same host', function () {
    $first = Project::factory()->create(['slug' => 'first']);
    $second = Project::factory()->create(['slug' => 'second']);
    Credential::factory()->for($first)->forHost('repo.magento.com')->withSecret('first-secret')->create(['username' => 'first-user']);
    Credential::factory()->for($second)->forHost('repo.magento.com')->withSecret('second-secret')->create(['username' => 'second-user']);
    [, $firstPlain] = tokenForProject($first);
    [, $secondPlain] = tokenForProject($second);

    expect(composerAuthRequest($firstPlain, 'first')->json()['http-basic']['repo.magento.com'])
        ->toBe(['username' => 'first-user', 'password' => 'first-secret'])
        ->and(composerAuthRequest($secondPlain, 'second')->json()['http-basic']['repo.magento.com'])
        ->toBe(['username' => 'second-user', 'password' => 'second-secret']);
});

it('answers an unknown project and another project identically', function () {
    $own = Project::factory()->create(['slug' => 'own']);
    $existing = Project::factory()->create(['slug' => 'existing']);
    Credential::factory()->for($existing)->withSecret('hidden-secret')->create();
    [, $tokenForUnknown] = tokenForProject($own);
    [, $tokenForOther] = tokenForProject($own);

    $unknown = composerAuthRequest($tokenForUnknown, 'does-not-exist');
    $unlinked = composerAuthRequest($tokenForOther, 'existing');

    assertApiError($unknown, 404, 'not_found');
    assertApiError($unlinked, 404, 'not_found');

    $headers = fn (TestResponse $response): array => collect($response->headers->all())->except(['date'])->all();

    expect($unlinked->getContent())->toBe($unknown->getContent())
        ->and($unlinked->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($headers($unlinked))->toBe($headers($unknown))
        ->and($unlinked->getContent())->not->toContain('hidden-secret');
});

it('logs an unknown project and another project with different results', function () {
    $own = Project::factory()->create(['slug' => 'own']);
    Project::factory()->create(['slug' => 'existing']);
    [$token, $plain] = tokenForProject($own);

    composerAuthRequest($plain, 'does-not-exist');
    composerAuthRequest($plain, 'existing');

    $logs = AccessLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->result)->toBe(AccessResult::UnknownProject)
        ->and($logs[0]->requested_slug)->toBe('does-not-exist')
        ->and($logs[0]->project_id)->toBeNull()
        ->and($logs[0]->access_token_id)->toBe($token->id)
        ->and($logs[1]->result)->toBe(AccessResult::ProjectDenied)
        ->and($logs[1]->requested_slug)->toBe('existing')
        ->and($logs[1]->project_id)->toBeNull()
        ->and($logs[1]->access_token_id)->toBe($token->id);
});

it('runs the same queries for an unknown and another project', function () {
    $this->freezeTime();
    $own = Project::factory()->create(['slug' => 'own']);
    Project::factory()->create(['slug' => 'existing']);
    [, $plain] = tokenForProject($own);
    composerAuthRequest($plain, 'own');
    composerAuthRequest($plain, 'warm-up-missing');
    $queryCount = function (string $slug) use ($plain): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        composerAuthRequest($plain, $slug);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queryCount('existing'))->toBe($queryCount('does-not-exist'));
});

it('logs a served request with the project', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    [$token, $plain] = tokenForProject($project);

    composerAuthRequest($plain, 'shop');

    $log = AccessLog::query()->sole();

    expect($log->result)->toBe(AccessResult::Ok)
        ->and($log->project_id)->toBe($project->id)
        ->and($log->access_token_id)->toBe($token->id)
        ->and($log->requested_slug)->toBeNull();
});

it('does not serve credentials to a revoked, expired or restricted token', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    Credential::factory()->for($project)->withSecret('guarded-secret')->create();
    $cases = [
        [AccessToken::factory()->revoked(), 401],
        [AccessToken::factory()->expired(), 401],
        [AccessToken::factory()->withAllowedIps(['10.0.0.0/8']), 403],
    ];

    foreach ($cases as [$factory, $status]) {
        [, $plain] = tokenForProject($project, $factory);

        $response = composerAuthRequest($plain, 'shop');

        expect($response->getStatusCode())->toBe($status)
            ->and($response->getContent())->not->toContain('guarded-secret');
    }
});

it('answers a decryption failure with a bare 500 and no partial credentials', function () {
    Exceptions::fake();
    $project = Project::factory()->create(['slug' => 'shop']);
    Credential::factory()->for($project)->forHost('good.example.com')->withSecret('good-secret')->create();
    $bad = Credential::factory()->for($project)->forHost('bad.example.com')->withSecret('bad-secret')->create();
    DB::table('credentials')->where('id', $bad->id)->update(['secret_ciphertext' => base64_encode('corrupted')]);
    [, $plain] = tokenForProject($project);

    $response = composerAuthRequest($plain, 'shop');

    assertApiError($response, 500, 'server_error');
    expect($response->getContent())->not->toContain('good-secret')
        ->not->toContain('bad-secret')
        ->not->toContain('good.example.com');
    Exceptions::assertReported(DecryptionFailedException::class);
});

it('does not write a secret to the access log', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    Credential::factory()->for($project)->withSecret('plaintext-secret-marker')->create();
    [, $plain] = tokenForProject($project);

    composerAuthRequest($plain, 'shop');

    expect(json_encode(DB::table('access_logs')->get()))->not->toContain('plaintext-secret-marker')
        ->not->toContain($plain);
});

it('does not serve a project to a token that belongs to another project', function () {
    $mine = Project::factory()->create(['slug' => 'mine']);
    $other = Project::factory()->create(['slug' => 'other']);
    Credential::factory()->for($other)->withSecret('other-secret')->create();
    [, $plain] = tokenForProject($mine);

    $response = composerAuthRequest($plain, 'other');

    assertApiError($response, 404, 'not_found');
    expect($response->getContent())->not->toContain('other-secret');
});
