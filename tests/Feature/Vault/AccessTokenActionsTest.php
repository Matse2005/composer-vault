<?php

use App\Actions\Vault\IssueAccessToken;
use App\Actions\Vault\RegenerateAccessToken;
use App\Actions\Vault\RevokeAccessToken;
use App\Exceptions\Vault\TokenIssuingFailedException;
use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use App\Vault\Tokens\IssuedToken;
use App\Vault\Tokens\TokenIssuer;
use Carbon\CarbonImmutable;
use Database\Factories\AccessTokenFactory;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function issueToken(?Project $project = null, ?array $allowedIps = null, ?CarbonImmutable $expiresAt = null, ?User $creator = null, string $name = 'prod-web-01'): IssuedToken
{
    return app(IssueAccessToken::class)->handle($project ?? Project::factory()->create(), $name, $allowedIps, $expiresAt, $creator);
}

/**
 * Bind a token issuer that returns the given plain tokens in order.
 *
 * @param  array<int, string>  $plainTokens
 */
function fakeTokenIssuer(array $plainTokens): void
{
    app()->instance(TokenIssuer::class, new class($plainTokens) extends TokenIssuer
    {
        /** @param array<int, string> $plainTokens */
        public function __construct(private array $plainTokens) {}

        public function generatePlainToken(): string
        {
            return array_shift($this->plainTokens) ?? parent::generatePlainToken();
        }
    });
}

it('issues a token with the settings and its project', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->create();
    $expiresAt = CarbonImmutable::now()->addMonth();

    $issued = issueToken($project, ['203.0.113.7', '10.0.0.0/8'], $expiresAt, $admin);
    $token = $issued->token->fresh();

    expect($issued->plainToken)->toMatch('/^cv_[A-Za-z0-9]{40}$/')
        ->and($token->name)->toBe('prod-web-01')
        ->and($token->token_prefix)->toBe(TokenIssuer::prefix($issued->plainToken))
        ->and($token->token_hash)->toBe(hash('sha256', $issued->plainToken))
        ->and($token->allowed_ips)->toBe(['203.0.113.7', '10.0.0.0/8'])
        ->and($token->expires_at->toDateTimeString())->toBe($expiresAt->toDateTimeString())
        ->and($token->created_by)->toBe($admin->id)
        ->and($token->revoked_at)->toBeNull()
        ->and($token->project_id)->toBe($project->id);
});

it('stores an empty allowlist as no restriction', function () {
    $token = issueToken(allowedIps: [])->token->fresh();

    expect($token->allowed_ips)->toBeNull();
});

it('does not store the plain token in any column', function () {
    $issued = issueToken();

    $row = (array) DB::table('access_tokens')->first();

    expect(json_encode($row))->not->toContain($issued->plainToken)
        ->not->toContain(substr($issued->plainToken, 3, 40));
});

it('rejects invalid allowlist entries and creates nothing', function (string $entry) {
    expect(fn () => issueToken(allowedIps: ['203.0.113.7', $entry]))->toThrow(InvalidArgumentException::class);

    expect(AccessToken::query()->count())->toBe(0);
})->with(['10.0.0.0/33', 'hello', '*', '']);

it('keeps the plain token out of dumps, json and serialization of the issued token', function () {
    $issued = issueToken();

    ob_start();
    var_dump($issued);
    $varDump = ob_get_clean();

    expect($varDump)->not->toContain($issued->plainToken)
        ->and(print_r($issued, true))->not->toContain($issued->plainToken)
        ->and(json_encode($issued))->not->toContain($issued->plainToken)
        ->and(fn () => serialize($issued))->toThrow(LogicException::class);
});

it('retries with a new token when the prefix is already taken', function () {
    AccessToken::factory()->create(['token_prefix' => 'AAAAAAAA']);
    $taken = 'cv_AAAAAAAA'.str_repeat('x', 32);
    $free = 'cv_BBBBBBBB'.str_repeat('y', 32);
    fakeTokenIssuer([$taken, $free]);

    $issued = issueToken();

    expect($issued->plainToken)->toBe($free)
        ->and($issued->token->fresh()->token_prefix)->toBe('BBBBBBBB')
        ->and(AccessToken::query()->count())->toBe(2);
});

it('gives up after three prefix collisions and creates nothing', function () {
    AccessToken::factory()->create(['token_prefix' => 'AAAAAAAA']);
    fakeTokenIssuer(array_fill(0, 3, 'cv_AAAAAAAA'.str_repeat('x', 32)));

    expect(fn () => issueToken())->toThrow(TokenIssuingFailedException::class);

    expect(AccessToken::query()->count())->toBe(1);
});

it('regenerates the token in place and invalidates the old value', function () {
    $project = Project::factory()->create();
    $issued = issueToken($project, ['203.0.113.7'], CarbonImmutable::now()->addWeek());
    $token = $issued->token;
    $token->forceFill(['last_used_at' => now(), 'last_used_ip' => '203.0.113.7'])->save();
    $before = $token->fresh();

    $regenerated = app(RegenerateAccessToken::class)->handle($token);
    $after = $token->fresh();

    expect($regenerated->plainToken)->toMatch('/^cv_[A-Za-z0-9]{40}$/')
        ->and($regenerated->plainToken)->not->toBe($issued->plainToken)
        ->and($after->getKey())->toBe($before->getKey())
        ->and($after->token_prefix)->toBe(TokenIssuer::prefix($regenerated->plainToken))
        ->and($after->token_prefix)->not->toBe($before->token_prefix)
        ->and($after->token_hash)->toBe(hash('sha256', $regenerated->plainToken))
        ->and($after->token_hash)->not->toBe(hash('sha256', $issued->plainToken))
        ->and($after->name)->toBe($before->name)
        ->and($after->allowed_ips)->toBe(['203.0.113.7'])
        ->and($after->expires_at->toDateTimeString())->toBe($before->expires_at->toDateTimeString())
        ->and($after->project_id)->toBe($project->id)
        ->and($after->revoked_at)->toBeNull()
        ->and($after->last_used_at)->toBeNull()
        ->and($after->last_used_ip)->toBeNull();
});

it('does not store the regenerated plain token in any column', function () {
    $issued = issueToken();

    $regenerated = app(RegenerateAccessToken::class)->handle($issued->token);

    expect(json_encode((array) DB::table('access_tokens')->first()))->not->toContain($regenerated->plainToken);
});

it('does not reactivate a revoked token when regenerating', function () {
    $token = AccessToken::factory()->revoked()->create();
    $revokedAt = $token->revoked_at;

    app(RegenerateAccessToken::class)->handle($token);

    expect($token->fresh()->revoked_at->toDateTimeString())->toBe($revokedAt->toDateTimeString());
});

it('revokes a token once and keeps the first timestamp when revoking again', function () {
    $token = issueToken()->token;

    $this->travelTo(now()->addDay());
    app(RevokeAccessToken::class)->handle($token);
    $firstRevokedAt = $token->fresh()->revoked_at;

    $this->travel(2)->days();
    app(RevokeAccessToken::class)->handle($token->fresh());

    expect($token->fresh()->isRevoked())->toBeTrue()
        ->and($token->fresh()->revoked_at->toDateTimeString())->toBe($firstRevokedAt->toDateTimeString());
});

it('builds factory tokens with a usable plain token', function () {
    $token = AccessToken::factory()->withPlainToken()->create();

    $plain = AccessTokenFactory::plainTokenFor($token);

    expect($plain)->toMatch('/^cv_[A-Za-z0-9]{40}$/')
        ->and($token->token_prefix)->toBe(TokenIssuer::prefix($plain))
        ->and($token->token_hash)->toBe(hash('sha256', $plain))
        ->and(json_encode((array) DB::table('access_tokens')->first()))->not->toContain($plain);
});
