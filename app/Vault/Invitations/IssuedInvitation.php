<?php

namespace App\Vault\Invitations;

use App\Models\Invitation;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * A freshly issued invitation with its link, which holds the plain token and is only shown once.
 * Dumps, JSON and serialisation never show the link.
 */
final readonly class IssuedInvitation implements JsonSerializable
{
    public function __construct(public Invitation $invitation, #[SensitiveParameter] public string $url) {}

    /**
     * @return array{invitation: Invitation, url: string}
     */
    public function __debugInfo(): array
    {
        return ['invitation' => $this->invitation, 'url' => '[hidden]'];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException Always, so the link cannot be serialised.
     */
    public function __serialize(): array
    {
        throw new LogicException('An issued invitation cannot be serialized.');
    }

    /**
     * @return array{invitation: string}
     */
    public function jsonSerialize(): array
    {
        return ['invitation' => (string) $this->invitation->getKey()];
    }
}
