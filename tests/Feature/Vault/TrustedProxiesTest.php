<?php

use App\Exceptions\Vault\InvalidTrustedProxyException;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__client-ip', fn (Request $request) => response($request->ip()));
});

it('uses the forwarded address from a trusted proxy', function () {
    config(['vault.trusted_proxies' => '10.0.0.1']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/__client-ip');

    expect($response->getContent())->toBe('203.0.113.9');
});

it('uses the forwarded address from a trusted proxy range', function () {
    config(['vault.trusted_proxies' => '172.16.0.0/12']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '172.20.1.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/__client-ip');

    expect($response->getContent())->toBe('203.0.113.9');
});

it('ignores the forwarded address from an untrusted source', function () {
    config(['vault.trusted_proxies' => '10.0.0.1']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/__client-ip');

    expect($response->getContent())->toBe('198.51.100.7');
});

it('trusts no proxy when none is configured', function () {
    config(['vault.trusted_proxies' => '']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/__client-ip');

    expect($response->getContent())->toBe('198.51.100.7');
});

it('does not fall back to trusting every proxy on a Forge hostname', function () {
    config(['vault.trusted_proxies' => '']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7', 'FORGE_SERVER_ID' => '1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('http://vault.on-forge.com/__client-ip');

    expect($response->getContent())->toBe('198.51.100.7');
});

it('refuses a wildcard at boot', function () {
    config(['vault.trusted_proxies' => '*']);

    (new AppServiceProvider($this->app))->boot();
})->throws(InvalidTrustedProxyException::class, 'TRUSTED_PROXIES');

it('refuses a wildcard when handling a request', function () {
    config(['vault.trusted_proxies' => '*']);

    $this->withoutExceptionHandling()->get('/__client-ip');
})->throws(InvalidTrustedProxyException::class);
