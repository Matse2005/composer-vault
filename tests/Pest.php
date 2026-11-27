<?php

use App\Models\AccessToken;
use Database\Factories\AccessTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

pest()->use(RefreshDatabase::class)->in('Feature');

pest()->beforeEach(function (): void {
    config(['vault.key' => vaultTestKey(), 'vault.key_version' => 1, 'vault.previous_keys' => '']);
})->in('Feature');

/**
 * Get a deterministic test key in the VAULT_KEY format. Different versions give different keys.
 */
function vaultTestKey(int $version = 1): string
{
    return 'base64:'.base64_encode(hash('sha256', "composer-vault-test-key-{$version}", true));
}

/**
 * Create an access token with a usable plain token.
 *
 * @return array{AccessToken, string}
 */
function tokenWithPlain(?AccessTokenFactory $factory = null): array
{
    $token = ($factory ?? AccessToken::factory())->withPlainToken()->create();

    return [$token, AccessTokenFactory::plainTokenFor($token)];
}

/**
 * Assert that a response is exactly the error body of the API contract, with the headers every API response carries.
 */
function assertApiError(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status);

    expect($response->getContent())->toBe(json_encode(['error' => $code]))
        ->and($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache');
}
