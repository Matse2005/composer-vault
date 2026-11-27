<?php

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

const SESSION_ENV_KEYS = [
    'SESSION_LIFETIME',
    'SESSION_EXPIRE_ON_CLOSE',
    'SESSION_ENCRYPT',
    'SESSION_SECURE_COOKIE',
    'SESSION_HTTP_ONLY',
    'SESSION_SAME_SITE',
    'APP_ENV',
];

/**
 * Evaluate config/session.php as if only the given environment variables were set, so the defaults of the file can be
 * tested independently of the .env file and of the test environment.
 *
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function sessionConfigWith(array $environment = []): array
{
    $previous = [];

    foreach (SESSION_ENV_KEYS as $key) {
        $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    foreach ($environment as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return require config_path('session.php');
    } finally {
        foreach (SESSION_ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            [$env, $server, $process] = $previous[$key];

            if ($env !== null) {
                $_ENV[$key] = $env;
            }

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }

            if ($process !== false) {
                putenv("{$key}={$process}");
            }
        }
    }
}

function sessionCookie(TestResponse $response): Cookie
{
    $cookies = collect($response->headers->getCookies())->filter(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookies)->toHaveCount(1);

    return $cookies->first();
}

it('defaults to hardened session settings', function () {
    $config = sessionConfigWith();

    expect($config['lifetime'])->toBe(60)
        ->and($config['expire_on_close'])->toBeFalse()
        ->and($config['encrypt'])->toBeTrue()
        ->and($config['http_only'])->toBeTrue()
        ->and($config['same_site'])->toBe('strict');
});

it('defaults to a secure cookie in production only', function (string $appEnv, bool $expected) {
    expect(sessionConfigWith(['APP_ENV' => $appEnv])['secure'])->toBe($expected);
})->with([
    'production' => ['production', true],
    'local' => ['local', false],
    'testing' => ['testing', false],
]);

it('lets the environment override the defaults', function () {
    $config = sessionConfigWith([
        'APP_ENV' => 'production',
        'SESSION_LIFETIME' => '30',
        'SESSION_SECURE_COOKIE' => 'false',
        'SESSION_SAME_SITE' => 'lax',
        'SESSION_ENCRYPT' => 'false',
    ]);

    expect($config['lifetime'])->toBe(30)
        ->and($config['secure'])->toBeFalse()
        ->and($config['same_site'])->toBe('lax')
        ->and($config['encrypt'])->toBeFalse();
});

it('sets an http-only strict session cookie on the login page', function () {
    $cookie = sessionCookie($this->get(route('login'))->assertOk());

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict');
});

it('sets a secure session cookie on a secure request in a production-like configuration', function () {
    config(['session.secure' => true]);

    $cookie = sessionCookie($this->get('https://vault.example.com/login')->assertOk());

    expect($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict');
});

it('does not mark the cookie secure when the configuration does not ask for it', function () {
    config(['session.secure' => false]);

    expect(sessionCookie($this->get(route('login')))->isSecure())->toBeFalse();
});
