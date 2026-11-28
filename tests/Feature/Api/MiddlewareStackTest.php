<?php

use App\Enums\AccessResult;
use App\Http\Middleware\AuthenticateAccessToken;
use App\Http\Middleware\BlockExcessiveFailures;
use App\Http\Middleware\RecordVaultAccess;
use App\Http\Middleware\SetNoStoreHeaders;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Vault\Settings\VaultSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Route::middleware(['api', 'vault-api'])->prefix('api')->get('v1/ping', fn () => response()->json(['ok' => true]));
});

function vaultApiGet(?string $plainToken, string $ip = '203.0.113.9', string $path = '/api/v1/ping'): TestResponse
{
    $headers = $plainToken === null ? [] : ['Authorization' => 'Bearer '.$plainToken];

    return test()->flushHeaders()->withHeaders($headers)->withServerVariables(['REMOTE_ADDR' => $ip])->getJson($path);
}

it('sets the no-store headers on 200, 401, 403, 404 and 429 responses', function () {
    [, $plain] = tokenWithPlain();
    [, $restricted] = tokenWithPlain(AccessToken::factory()->withAllowedIps(['10.0.0.0/8']));
    config(['vault.api.rate_limit_per_minute' => 1]);

    $ok = vaultApiGet($plain);
    $unauthenticated = vaultApiGet(null);
    $forbidden = vaultApiGet($restricted);
    $notFound = vaultApiGet($plain, path: '/api/v1/projects/shop/composer-auth');
    $limited = vaultApiGet($plain);

    $ok->assertOk();
    assertApiError($unauthenticated, 401, 'unauthenticated');
    assertApiError($forbidden, 403, 'forbidden');
    assertApiError($limited, 429, 'too_many_requests');

    foreach ([$ok, $unauthenticated, $forbidden, $notFound, $limited] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Pragma'))->toBe('no-cache');
    }
});

it('gives the identical 401 for missing, malformed, unknown, revoked and expired tokens', function () {
    [, $revoked] = tokenWithPlain(AccessToken::factory()->revoked());
    [, $expired] = tokenWithPlain(AccessToken::factory()->expired());

    $responses = [
        vaultApiGet(null),
        vaultApiGet('not-a-token'),
        vaultApiGet('cv_'.str_repeat('Z', 40)),
        vaultApiGet($revoked),
        vaultApiGet($expired),
    ];

    foreach ($responses as $response) {
        assertApiError($response, 401, 'unauthenticated');
        expect($response->getContent())->toBe($responses[0]->getContent())
            ->and($response->headers->get('Content-Type'))->toBe($responses[0]->headers->get('Content-Type'))
            ->and($response->headers->get('Cache-Control'))->toBe($responses[0]->headers->get('Cache-Control'));
    }
});

it('gives 403 only for a valid token from an address outside its allowlist', function () {
    [, $plain] = tokenWithPlain(AccessToken::factory()->withAllowedIps(['10.0.0.0/8']));

    assertApiError(vaultApiGet($plain, '203.0.113.9'), 403, 'forbidden');
    vaultApiGet($plain, '10.1.2.3')->assertOk();
    assertApiError(vaultApiGet('cv_'.str_repeat('Z', 40), '203.0.113.9'), 401, 'unauthenticated');
});

it('blocks an address after too many failed attempts, even with a valid token', function () {
    [, $plain] = tokenWithPlain();

    foreach (range(1, 10) as $attempt) {
        assertApiError(vaultApiGet('cv_'.str_repeat('Z', 40), '198.51.100.7'), 401, 'unauthenticated');
    }

    $blocked = vaultApiGet($plain, '198.51.100.7');

    assertApiError($blocked, 429, 'too_many_requests');
    expect($blocked->headers->get('Retry-After'))->not->toBeNull();
    vaultApiGet($plain, '198.51.100.8')->assertOk();
});

it('lets the tenth failed attempt through and blocks the eleventh', function () {
    foreach (range(1, 10) as $attempt) {
        assertApiError(vaultApiGet(null, '198.51.100.7'), 401, 'unauthenticated');
    }

    assertApiError(vaultApiGet(null, '198.51.100.7'), 429, 'too_many_requests');
});

it('counts forbidden responses as failed attempts but not successful ones', function () {
    [, $restricted] = tokenWithPlain(AccessToken::factory()->withAllowedIps(['10.0.0.0/8']));
    [, $plain] = tokenWithPlain();
    config(['vault.api.failed_attempts_per_minute' => 3]);

    foreach (range(1, 20) as $attempt) {
        vaultApiGet($plain, '198.51.100.7')->assertOk();
    }

    foreach (range(1, 3) as $attempt) {
        assertApiError(vaultApiGet($restricted, '198.51.100.7'), 403, 'forbidden');
    }

    assertApiError(vaultApiGet($plain, '198.51.100.7'), 429, 'too_many_requests');
});

it('limits a token to 60 requests a minute and leaves other tokens alone', function () {
    [, $plain] = tokenWithPlain();
    [, $other] = tokenWithPlain();

    foreach (range(1, 60) as $request) {
        vaultApiGet($plain)->assertOk();
    }

    $limited = vaultApiGet($plain);

    assertApiError($limited, 429, 'too_many_requests');
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
    vaultApiGet($other)->assertOk();
});

it('does not count the token rate limit as failed attempts', function () {
    [, $plain] = tokenWithPlain();
    [, $other] = tokenWithPlain();
    config(['vault.api.rate_limit_per_minute' => 2]);

    foreach (range(1, 15) as $request) {
        vaultApiGet($plain, '198.51.100.7');
    }

    vaultApiGet($other, '198.51.100.7')->assertOk();
});

it('writes exactly one access log row per request with the right result', function () {
    [$ok, $okPlain] = tokenWithPlain();
    [$revoked, $revokedPlain] = tokenWithPlain(AccessToken::factory()->revoked());
    [$expired, $expiredPlain] = tokenWithPlain(AccessToken::factory()->expired());
    [$denied, $deniedPlain] = tokenWithPlain(AccessToken::factory()->withAllowedIps(['10.0.0.0/8']));
    [$limited, $limitedPlain] = tokenWithPlain();
    config(['vault.api.rate_limit_per_minute' => 1]);

    $requests = [
        [$okPlain, '203.0.113.9', AccessResult::Ok, $ok],
        [null, '203.0.113.9', AccessResult::UnknownToken, null],
        ['cv_'.str_repeat('Z', 40), '203.0.113.9', AccessResult::UnknownToken, null],
        [$revokedPlain, '203.0.113.9', AccessResult::Revoked, $revoked],
        [$expiredPlain, '203.0.113.9', AccessResult::Expired, $expired],
        [$deniedPlain, '203.0.113.9', AccessResult::IpDenied, $denied],
        [$limitedPlain, '203.0.113.9', AccessResult::Ok, $limited],
        [$limitedPlain, '203.0.113.9', AccessResult::RateLimited, $limited],
    ];

    foreach ($requests as $index => [$plain, $ip, $result, $token]) {
        vaultApiGet($plain, $ip);

        expect(AccessLog::query()->count())->toBe($index + 1);

        $log = AccessLog::query()->latest('id')->first();

        expect($log->result)->toBe($result)
            ->and($log->access_token_id)->toBe($token?->id)
            ->and($log->ip)->toBe($ip);
    }
});

it('logs a request blocked for too many failures as rate limited without a token', function () {
    [, $plain] = tokenWithPlain();

    foreach (range(1, 10) as $attempt) {
        vaultApiGet(null, '198.51.100.7');
    }

    vaultApiGet($plain, '198.51.100.7');

    $log = AccessLog::query()->latest('id')->first();

    expect(AccessLog::query()->count())->toBe(11)
        ->and($log->result)->toBe(AccessResult::RateLimited)
        ->and($log->access_token_id)->toBeNull();
});

it('keeps the response when the access log cannot be written', function () {
    [, $plain] = tokenWithPlain();
    Schema::drop('access_logs');

    vaultApiGet($plain)->assertOk();
});

it('defines the stack in the documented order', function () {
    $middleware = collect(Route::getMiddlewareGroups()['vault-api'] ?? [])->values()->all();

    expect($middleware)->toBe([
        SetNoStoreHeaders::class,
        RecordVaultAccess::class,
        BlockExcessiveFailures::class,
        AuthenticateAccessToken::class,
        'throttle:vault-api',
    ]);
});

it('runs the stack in the documented order after Laravel has sorted the middleware', function () {
    $route = Route::getRoutes()->match(Request::create('/api/v1/ping'));
    $executed = array_map(
        fn (string $middleware) => explode(':', $middleware)[0],
        app('router')->resolveMiddleware($route->gatherMiddleware()),
    );
    $vault = array_values(array_intersect($executed, [
        SetNoStoreHeaders::class,
        RecordVaultAccess::class,
        BlockExcessiveFailures::class,
        AuthenticateAccessToken::class,
        ThrottleRequests::class,
    ]));

    expect($vault)->toBe([
        SetNoStoreHeaders::class,
        RecordVaultAccess::class,
        BlockExcessiveFailures::class,
        AuthenticateAccessToken::class,
        ThrottleRequests::class,
    ]);
});

it('uses the default limits of 60 requests and 10 failures a minute', function () {
    expect(app(VaultSettings::class)->apiRateLimitPerMinute())->toBe(60)
        ->and(app(VaultSettings::class)->apiFailedAttemptsPerMinute())->toBe(10);
});
