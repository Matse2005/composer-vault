<?php

use App\Actions\Vault\RegenerateAccessToken;
use App\Enums\AccessResult;
use App\Models\AccessToken;
use App\Vault\Tokens\TokenAuthenticationResult;
use App\Vault\Tokens\TokenAuthenticator;
use Database\Factories\AccessTokenFactory;

function authenticate(?string $bearer, string $ip = '203.0.113.9'): TokenAuthenticationResult
{
    return app(TokenAuthenticator::class)->authenticate($bearer, $ip);
}

/**
 * @return array{AccessToken, string}
 */
function tokenWithPlain(?AccessTokenFactory $factory = null): array
{
    $token = ($factory ?? AccessToken::factory())->withPlainToken()->create();

    return [$token, AccessTokenFactory::plainTokenFor($token)];
}

it('accepts a valid token and records the use without touching updated_at', function () {
    [$token, $plain] = tokenWithPlain();
    $updatedAt = $token->fresh()->updated_at;

    $this->travel(10)->minutes();
    $result = authenticate($plain, '203.0.113.9');

    $fresh = $token->fresh();

    expect($result->result)->toBe(AccessResult::Ok)
        ->and($result->isOk())->toBeTrue()
        ->and($result->token->is($token))->toBeTrue()
        ->and($fresh->last_used_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($fresh->last_used_ip)->toBe('203.0.113.9')
        ->and($fresh->updated_at->toDateTimeString())->toBe($updatedAt->toDateTimeString());
});

it('treats a missing or malformed header as an unknown token', function (?string $bearer) {
    [$token] = tokenWithPlain();

    $result = authenticate($bearer);

    expect($result->result)->toBe(AccessResult::UnknownToken)
        ->and($result->token)->toBeNull()
        ->and($token->fresh()->last_used_at)->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'no prefix' => [str_repeat('a', 43)],
    'too short' => ['cv_'.str_repeat('a', 39)],
    'too long' => ['cv_'.str_repeat('a', 41)],
    'invalid characters' => ['cv_'.str_repeat('a', 39).'-'],
    'trailing newline' => ['cv_'.str_repeat('a', 40)."\n"],
    'bearer word included' => ['Bearer cv_'.str_repeat('a', 40)],
    'wrong case of the prefix' => ['CV_'.str_repeat('a', 40)],
]);

it('treats an unknown prefix as an unknown token', function () {
    tokenWithPlain();

    $result = authenticate('cv_ZZZZZZZZ'.str_repeat('q', 32));

    expect($result->result)->toBe(AccessResult::UnknownToken)->and($result->token)->toBeNull();
});

it('treats a valid prefix with a wrong remainder as an unknown token', function () {
    [$token, $plain] = tokenWithPlain();
    $wrong = substr($plain, 0, 11).strrev(substr($plain, 11));
    $wrong = $wrong === $plain ? substr($plain, 0, 42).'x' : $wrong;

    $result = authenticate($wrong);

    expect($result->result)->toBe(AccessResult::UnknownToken)
        ->and($result->token)->toBeNull()
        ->and($token->fresh()->last_used_at)->toBeNull();
});

it('reports a revoked token', function () {
    [$token, $plain] = tokenWithPlain(AccessToken::factory()->revoked());

    $result = authenticate($plain);

    expect($result->result)->toBe(AccessResult::Revoked)
        ->and($result->token->is($token))->toBeTrue()
        ->and($token->fresh()->last_used_at)->toBeNull();
});

it('reports an expired token', function () {
    [$token, $plain] = tokenWithPlain(AccessToken::factory()->expired());

    $result = authenticate($plain);

    expect($result->result)->toBe(AccessResult::Expired)
        ->and($result->token->is($token))->toBeTrue()
        ->and($token->fresh()->last_used_at)->toBeNull();
});

it('accepts a token that expires in the future', function () {
    [, $plain] = tokenWithPlain(AccessToken::factory()->state(['expires_at' => now()->addDay()]));

    expect(authenticate($plain)->result)->toBe(AccessResult::Ok);
});

it('denies an address outside the allowlist and does not record a use', function () {
    [$token, $plain] = tokenWithPlain(AccessToken::factory()->withAllowedIps(['10.0.0.0/8', '2001:db8::/32']));

    $result = authenticate($plain, '203.0.113.9');

    expect($result->result)->toBe(AccessResult::IpDenied)
        ->and($result->token->is($token))->toBeTrue()
        ->and($token->fresh()->last_used_at)->toBeNull()
        ->and($token->fresh()->last_used_ip)->toBeNull();
});

it('accepts an address inside the allowlist', function (string $ip) {
    [, $plain] = tokenWithPlain(AccessToken::factory()->withAllowedIps(['10.0.0.0/8', '2001:db8::/32', '198.51.100.7']));

    expect(authenticate($plain, $ip)->result)->toBe(AccessResult::Ok);
})->with(['10.200.1.1', '2001:db8:1::5', '198.51.100.7']);

it('accepts any address for an empty allowlist', function () {
    [, $plain] = tokenWithPlain(AccessToken::factory()->withAllowedIps([]));

    expect(authenticate($plain, '203.0.113.9')->result)->toBe(AccessResult::Ok);
});

it('checks revoked before expired before the allowlist', function () {
    [, $revokedAndExpired] = tokenWithPlain(AccessToken::factory()->revoked()->expired()->withAllowedIps(['10.0.0.0/8']));
    [, $expiredAndDenied] = tokenWithPlain(AccessToken::factory()->expired()->withAllowedIps(['10.0.0.0/8']));

    expect(authenticate($revokedAndExpired)->result)->toBe(AccessResult::Revoked)
        ->and(authenticate($expiredAndDenied)->result)->toBe(AccessResult::Expired);
});

it('does not reveal the state of a token when the secret part is wrong', function () {
    [, $plain] = tokenWithPlain(AccessToken::factory()->revoked());
    $wrong = substr($plain, 0, 42).($plain[42] === 'a' ? 'b' : 'a');

    expect(authenticate($wrong)->result)->toBe(AccessResult::UnknownToken);
});

it('refuses the old value after regeneration and accepts the new one', function () {
    [$token, $oldPlain] = tokenWithPlain();

    $new = app(RegenerateAccessToken::class)->handle($token);

    expect(authenticate($oldPlain)->result)->toBe(AccessResult::UnknownToken)
        ->and(authenticate($new->plainToken)->result)->toBe(AccessResult::Ok);
});

it('only records a use on success', function () {
    [$ok, $okPlain] = tokenWithPlain();
    [$denied, $deniedPlain] = tokenWithPlain(AccessToken::factory()->withAllowedIps(['10.0.0.0/8']));

    authenticate($deniedPlain);
    authenticate($okPlain);

    expect($denied->fresh()->last_used_at)->toBeNull()
        ->and($ok->fresh()->last_used_at)->not->toBeNull();
});
