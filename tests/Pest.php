<?php

use App\Models\AccessToken;
use Database\Factories\AccessTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

pest()->use(RefreshDatabase::class)->in('Feature');

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Browser');

// The browser tests load the built assets, never a running Vite dev server, so the CSP is checked on what ships.
pest()->beforeEach(function (): void {
    app(Vite::class)->useHotFile(storage_path('framework/no-vite-hot-file'));
    config(['vault.key' => vaultTestKey(), 'vault.key_version' => 1, 'vault.previous_keys' => '']);
})->in('Browser');

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

/**
 * Assert that a decoded composer-auth body has the shapes of docs/api.md: only the four Composer auth keys, each
 * with at least one host. http-basic hosts hold exactly a username and a password, the other types a token string.
 *
 * @param  array<string, mixed>  $body
 */
function assertComposerAuthShape(array $body): void
{
    expect(array_diff(array_keys($body), ['http-basic', 'bearer', 'github-oauth', 'gitlab-token']))->toBe([]);

    foreach ($body as $type => $hosts) {
        expect($hosts)->toBeArray()->not->toBeEmpty()
            ->and(array_is_list($hosts))->toBeFalse();

        foreach ($hosts as $host => $entry) {
            expect((string) $host)->toMatch('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/');

            if ($type === 'http-basic') {
                expect($entry)->toBeArray()
                    ->and(array_keys($entry))->toBe(['username', 'password'])
                    ->and($entry['username'])->toBeString()
                    ->and($entry['password'])->toBeString();
            } else {
                expect($entry)->toBeString()->not->toBeEmpty();
            }
        }
    }
}
