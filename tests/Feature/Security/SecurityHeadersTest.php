<?php

use App\Models\User;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Headers are not added while Vite runs hot in debug mode, and a local public/hot file would trigger that.
    config(['app.debug' => false]);
    app(Vite::class)->useHotFile(storage_path('framework/no-vite-hot-file'));
});

it('sets the fixed security headers and the policy on the login page and the dashboard', function (string $page, bool $signedIn) {
    if ($signedIn) {
        $this->actingAs(User::factory()->withTwoFactor()->create());
    }

    $response = $this->get($page)->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=(), payment=(), usb=(), publickey-credentials-create=(self), publickey-credentials-get=(self)')
        ->and($response->headers->has('Content-Security-Policy'))->toBeTrue();
})->with(['login page' => ['/login', false], 'dashboard' => ['/dashboard', true]]);

it('sets the headers on a redirect and on a Livewire page too', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $redirect = $this->get('/settings')->assertRedirect();
    $page = $this->get('/settings/appearance')->assertOk();

    expect($redirect->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($page->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($page->headers->has('Content-Security-Policy'))->toBeTrue();
});

it('sets Strict-Transport-Security on secure requests only', function () {
    $secure = $this->get('https://vault.example.com/login');
    $plain = $this->get('http://vault.example.com/login');

    expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains')
        ->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('gives API responses nosniff but no content security policy', function () {
    $response = $this->getJson('/api/v1/projects/shop/composer-auth');

    assertApiError($response, 401, 'unauthenticated');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
        ->and($response->headers->has('X-Frame-Options'))->toBeFalse();
});

it('gives successful API responses nosniff as well', function () {
    [, $plain] = tokenWithPlain();
    Route::middleware(['api', 'vault-api'])->prefix('api')->get('v1/ping', fn () => response()->json(['ok' => true]));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/api/v1/ping')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});
