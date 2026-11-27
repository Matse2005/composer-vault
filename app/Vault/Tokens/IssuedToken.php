<?php

namespace App\Vault\Tokens;

use App\Models\AccessToken;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * A freshly issued or regenerated token together with its plain value, which exists only in this object.
 * Dumps, JSON and serialisation never show the plain token.
 */
final readonly class IssuedToken implements JsonSerializable
{
    public function __construct(public AccessToken $token, #[SensitiveParameter] public string $plainToken) {}

    /**
     * @return array{token: AccessToken, plainToken: string}
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->token, 'plainToken' => '[hidden]'];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException Always, so the plain token cannot be serialised.
     */
    public function __serialize(): array
    {
        throw new LogicException('An issued token cannot be serialized.');
    }

    /**
     * @return array{token: string}
     */
    public function jsonSerialize(): array
    {
        return ['token' => (string) $this->token->getKey()];
    }
}
