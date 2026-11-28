<?php

use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\User;
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

/**
 * Run a callback as a temporary Owner who is logged in, then log out and remove them again. For tests that call an
 * action outside a request but must keep the number of users as it is.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function asOwner(callable $callback): mixed
{
    $previous = auth()->user();
    $owner = User::factory()->create();
    test()->actingAs($owner);

    try {
        return $callback();
    } finally {
        auth()->forgetGuards();

        if ($previous !== null) {
            test()->actingAs($previous);
        }

        $owner->delete();
    }
}

/**
 * Start a sign-in and return the state and nonce the vault put in the redirect.
 *
 * @return array{state: string, nonce: string, url: string}
 */
function startSso(): array
{
    $response = test()->get(route('sso.redirect'));
    $response->assertRedirect();
    $url = $response->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return ['state' => $query['state'], 'nonce' => $query['nonce'], 'url' => $url];
}

/**
 * Run the callback with an ID token built from the claims.
 *
 * @param  array<string, mixed>  $claims
 */
function finishSso(array $claims = [], ?string $state = null, bool $useNonce = true): TestResponse
{
    $started = startSso();
    test()->idp->nextIdToken = test()->idp->idToken($useNonce ? $started['nonce'] : 'other-nonce', $claims);

    return test()->get(route('sso.callback', ['code' => 'auth-code', 'state' => $state ?? $started['state']]));
}

function lastRejection(): ?string
{
    return AuditEntry::query()->where('event', 'sso_login_rejected')->latest('sequence')->first()?->properties['reason'] ?? null;
}

/**
 * A user with a custom role holding exactly these permissions.
 *
 * @param  list<string>  $permissions
 */
function userWithPermissions(array $permissions, bool $twoFactor = true): User
{
    $role = Role::factory()->withPermissions($permissions)->create();
    $user = $twoFactor ? User::factory()->withTwoFactor()->create() : User::factory()->create();
    $role->assignTo($user);
    $user->save();

    return $user;
}
