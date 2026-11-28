<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\AccessToken;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Tokens\IpAllowlist;
use App\Vault\Tokens\TokenPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class UpdateAccessToken
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Update the settings of a token. The token value, its project, its status and its last use are not touched. A token cannot move to another project: issue a new one instead. One entry is
     * audited, with the settings that changed and their old and new value.
     *
     * @param  array<int, string>|null  $allowedIps  IP addresses and CIDR ranges. Null or empty means no restriction.
     *
     * @throws InvalidArgumentException When an allowlist entry is not an IP address or CIDR range.
     */
    public function handle(AccessToken $token, string $name, ?array $allowedIps, ?CarbonImmutable $expiresAt): AccessToken
    {
        Gate::authorize(Ability::TokensManage);

        if ($token->expires_at?->getTimestamp() !== $expiresAt?->getTimestamp()) {
            TokenPolicy::assertAllows($expiresAt);
        }

        $allowedIps = IpAllowlist::normalizeList($allowedIps);

        return DB::transaction(function () use ($token, $name, $allowedIps, $expiresAt): AccessToken {
            $changes = [];

            if ($token->name !== $name) {
                $changes['name'] = ['old' => $token->name, 'new' => $name];
            }

            if ($token->allowed_ips !== $allowedIps) {
                $changes['allowed_ips'] = ['old' => $token->allowed_ips, 'new' => $allowedIps];
            }

            if ($token->expires_at?->getTimestamp() !== $expiresAt?->getTimestamp()) {
                $changes['expires_at'] = ['old' => $token->expires_at, 'new' => $expiresAt];
            }

            $token->forceFill(['name' => $name, 'allowed_ips' => $allowedIps, 'expires_at' => $expiresAt])->save();

            $this->auditLogger->record(AuditAction::TokenUpdated, $token, $changes);

            return $token;
        });
    }
}
