<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * A fake identity provider for tests: discovery, key set and token endpoint go through Http::fake, and ID tokens are
 * signed with a key pair made for the test. No test touches a real provider or a network.
 */
class FakeOidcProvider
{
    public const ISSUER = 'https://idp.example.test';

    public const CLIENT_ID = 'vault-client';

    public const KEY_ID = 'test-key-1';

    /** @var array<string, mixed> */
    public array $metadata;

    private mixed $privateKey;

    /** @var array<string, string> */
    private array $jwk;

    /** The ID token the next token request returns, or null to fail the exchange. */
    public ?string $nextIdToken = null;

    public int $tokenRequests = 0;

    public string $userinfoSubject = 'subject-1';

    /** @var array<string, mixed> */
    public array $lastTokenRequest = [];

    public function __construct(?string $keyId = null)
    {
        $this->privateKey = self::newPrivateKey();
        $this->jwk = self::jwkOf($this->privateKey, $keyId ?? self::KEY_ID);

        $this->metadata = [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/authorize',
            'token_endpoint' => self::ISSUER.'/token',
            'userinfo_endpoint' => self::ISSUER.'/userinfo',
            'jwks_uri' => self::ISSUER.'/jwks',
            'end_session_endpoint' => self::ISSUER.'/logout',
        ];
    }

    /**
     * Configure the vault for this provider and fake its three endpoints.
     *
     * @param  array<string, mixed>  $config
     */
    public function install(array $config = []): self
    {
        config([
            'vault.sso.enabled' => true,
            'vault.sso.issuer' => self::ISSUER,
            'vault.sso.client_id' => self::CLIENT_ID,
            'vault.sso.client_secret' => 'client-secret-value',
            ...$config,
        ]);

        $this->fake();

        return $this;
    }

    public function fake(): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => fn () => Http::response($this->metadata),
            self::ISSUER.'/jwks' => fn () => Http::response(['keys' => [$this->jwk]]),
        ]);

        // Socialite sends the token and userinfo requests through Guzzle, not through Http::fake.
        app()->instance('vault.sso.http', new Client(['handler' => HandlerStack::create(function (RequestInterface $request) {
            $path = $request->getUri()->getPath();

            if ($path === '/userinfo') {
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode(['sub' => $this->userinfoSubject])));
            }

            $this->tokenRequests++;
            parse_str((string) $request->getBody(), $body);
            $this->lastTokenRequest = $body;

            return Create::promiseFor($this->nextIdToken === null
                ? new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant']))
                : new Response(200, ['Content-Type' => 'application/json'], json_encode(['id_token' => $this->nextIdToken, 'access_token' => 'access-token-value', 'refresh_token' => 'refresh-token-value', 'token_type' => 'Bearer'])));
        })]));
    }

    /**
     * Sign an ID token with the key of this provider.
     *
     * @param  array<string, mixed>  $overrides  claims to set, or null to remove one
     */
    public function idToken(string $nonce, array $overrides = [], ?string $keyId = null, mixed $signingKey = null): string
    {
        $claims = [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-1',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => $nonce,
            'email' => 'person@example.test',
            'email_verified' => true,
            'name' => 'Person Example',
            ...$overrides,
        ];

        $claims = array_filter($claims, fn (mixed $value): bool => $value !== null);

        return JWT::encode($claims, $signingKey ?? $this->privateKey, 'RS256', $keyId ?? $this->jwk['kid']);
    }

    /**
     * Replace the signing key, as a provider does when it rotates keys. The old key stays unknown to the vault until
     * it fetches the key set again.
     */
    public function rotateKey(string $keyId): void
    {
        $this->privateKey = self::newPrivateKey();
        $this->jwk = self::jwkOf($this->privateKey, $keyId);
    }

    public static function newPrivateKey(): mixed
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new \RuntimeException('Could not create a test key. Is the openssl extension enabled?');
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    private static function jwkOf(mixed $privateKey, string $keyId): array
    {
        $details = openssl_pkey_get_details($privateKey);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $keyId,
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];
    }
}
