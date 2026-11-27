<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Assert that a response is exactly the contract's error body, with the headers every API response carries.
 */
function assertApiError(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status);

    expect($response->getContent())->toBe(json_encode(['error' => $code]))
        ->and($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache');
}

it('registers the composer auth route under the v1 prefix', function () {
    expect(route('api.v1.composer-auth', ['slug' => 'my-project'], absolute: false))
        ->toBe('/api/v1/projects/my-project/composer-auth');
});

it('answers an unknown api path with the contract 404', function () {
    assertApiError($this->getJson('/api/v1/does-not-exist'), 404, 'not_found');
    assertApiError($this->get('/api/unknown'), 404, 'not_found');
});

it('answers a wrong method with the contract 404', function (string $method) {
    assertApiError($this->json($method, '/api/v1/projects/my-project/composer-auth'), 404, 'not_found');
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('answers a slug that does not match the pattern with the contract 404', function (string $slug) {
    assertApiError($this->getJson("/api/v1/projects/{$slug}/composer-auth"), 404, 'not_found');
})->with(['Bad_Slug', 'UPPER', '1starts-with-digit', 'a', 'has.dot']);

it('answers a failing api route with a bare 500 even when debug is on', function () {
    config(['app.debug' => true]);
    Route::middleware('api')->prefix('api')->get('v1/boom', function (): never {
        throw new RuntimeException('secret internal message');
    });

    $response = $this->getJson('/api/v1/boom');

    assertApiError($response, 500, 'server_error');
    expect($response->getContent())->not->toContain('secret internal message')
        ->not->toContain('RuntimeException')
        ->not->toContain('trace')
        ->not->toContain('file');
});

it('answers a throttled api route with the contract 429 and keeps Retry-After', function () {
    Route::middleware(['api', 'throttle:1,1'])->prefix('api')->get('v1/limited', fn () => response()->json([]));

    $this->getJson('/api/v1/limited')->assertOk();
    $response = $this->getJson('/api/v1/limited');

    assertApiError($response, 429, 'too_many_requests');
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

it('does not change how the web routes render a missing page', function () {
    $response = $this->get('/no-such-page');

    $response->assertNotFound();
    expect($response->getContent())->not->toBe(json_encode(['error' => 'not_found']));
});

it('has no session or csrf middleware on the api routes', function () {
    Route::middleware('api')->prefix('api')->post('v1/echo', fn (Request $request) => response()->json(['session' => $request->hasSession() && $request->session()->isStarted()]));

    $this->postJson('/api/v1/echo')->assertOk()->assertExactJson(['session' => false]);
});
