<?php

use App\Exceptions\Vault\InvalidAdminIpAllowlistException;
use App\Providers\AppServiceProvider;
use App\Vault\Tokens\AdminIpList;
use Illuminate\Testing\TestResponse;

function requestFrom(string $ip, string $url = '/login', array $headers = []): TestResponse
{
    return test()->flushHeaders()->withHeaders($headers)->withServerVariables(['REMOTE_ADDR' => $ip])->get($url);
}

it('has no effect when the allowlist is empty', function (mixed $configured) {
    config(['vault.admin.allowed_ips' => $configured]);

    requestFrom('203.0.113.9')->assertOk();
})->with(['empty string' => [''], 'null' => [null], 'empty array' => [[]], 'blank' => ['  ']]);

it('is empty by default', function () {
    expect(AdminIpList::parse(config('vault.admin.allowed_ips')))->toBe([]);
});

it('lets an allowed address reach the login page', function () {
    config(['vault.admin.allowed_ips' => '203.0.113.9']);

    requestFrom('203.0.113.9')->assertOk();
});

it('answers any other address with a plain 403 on the login page and the invitation page', function (string $url) {
    config(['vault.admin.allowed_ips' => '203.0.113.9']);

    $response = requestFrom('198.51.100.7', $url);

    $response->assertForbidden();
    expect($response->getContent())->toBe('Forbidden')
        ->not->toContain('<html')
        ->not->toContain(config('app.name'))
        ->and($response->headers->get('Content-Type'))->toContain('text/plain');
})->with(['login page' => ['/login'], 'invitation page' => ['/invitations/'.str_repeat('a', 48)], 'home' => ['/']]);

it('matches ranges and several entries', function (string $configured, string $ip, bool $allowed) {
    config(['vault.admin.allowed_ips' => $configured]);

    $response = requestFrom($ip);

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'ipv4 cidr inside' => ['10.0.0.0/8', '10.20.30.40', true],
    'ipv4 cidr outside' => ['10.0.0.0/8', '11.0.0.1', false],
    'ipv6 inside' => ['2001:db8::/32', '2001:db8:1::5', true],
    'ipv6 outside' => ['2001:db8::/32', '2001:db9::1', false],
    'second entry matches' => ['198.51.100.1, 203.0.113.0/24', '203.0.113.50', true],
    'none of several matches' => ['198.51.100.1, 203.0.113.0/24', '192.0.2.1', false],
    'array in config' => [' 203.0.113.9 ', '203.0.113.9', true],
]);

it('accepts the list as an array in the configuration', function () {
    config(['vault.admin.allowed_ips' => ['203.0.113.9', '10.0.0.0/8']]);

    requestFrom('10.1.1.1')->assertOk();
    requestFrom('192.0.2.1')->assertForbidden();
});

it('blocks the Livewire update route for other addresses', function () {
    config(['vault.admin.allowed_ips' => '203.0.113.9']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson(route('default-livewire.update'), ['components' => []], ['X-Livewire' => 'true'])
        ->assertForbidden();
});

it('does not start a session for a blocked address', function () {
    config(['vault.admin.allowed_ips' => '203.0.113.9']);

    $response = requestFrom('198.51.100.7');

    expect(collect($response->headers->getCookies())->map->getName()->all())->not->toContain(config('session.cookie'));
});

it('leaves the API and the health route alone', function () {
    config(['vault.admin.allowed_ips' => '203.0.113.9']);

    assertApiError(requestFrom('198.51.100.7', '/api/v1/projects/shop/composer-auth'), 401, 'unauthenticated');
    requestFrom('198.51.100.7', '/up')->assertOk();
});

it('uses the forwarded address from a trusted proxy and ignores it from anyone else', function () {
    config(['vault.admin.allowed_ips' => '203.0.113.0/24', 'vault.trusted_proxies' => '10.0.0.1']);

    requestFrom('10.0.0.1', headers: ['X-Forwarded-For' => '203.0.113.9'])->assertOk();
    requestFrom('10.0.0.1', headers: ['X-Forwarded-For' => '192.0.2.1'])->assertForbidden();
    requestFrom('192.0.2.1', headers: ['X-Forwarded-For' => '203.0.113.9'])->assertForbidden();
});

it('refuses invalid entries at boot with a clear message', function (string $configured) {
    config(['vault.admin.allowed_ips' => $configured]);

    (new AppServiceProvider($this->app))->boot();
})->with([
    'hostname' => ['hello'],
    'wildcard' => ['203.0.113.9, *'],
    'prefix too long' => ['10.0.0.0/33'],
    'range with a dash' => ['10.0.0.1-10.0.0.9'],
])->throws(InvalidAdminIpAllowlistException::class, 'VAULT_ADMIN_ALLOWED_IPS');

it('parses a comma separated list', function () {
    expect(AdminIpList::parse(' 10.0.0.0/8 , 203.0.113.9,, 2001:db8::1 '))->toBe(['10.0.0.0/8', '203.0.113.9', '2001:db8::1']);
});
