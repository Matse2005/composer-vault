<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\AccessToken;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Tokens\IpAllowlist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class UpdateAccessToken
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Update the settings of a token. The token value, its status and its last use are not touched. One entry is
     * audited, with the settings that changed and their old and new value.
     *
     * @param  array<int, string>  $projectIds
     * @param  array<int, string>|null  $allowedIps  IP addresses and CIDR ranges. Null or empty means no restriction.
     *
     * @throws InvalidArgumentException When an allowlist entry is not an IP address or CIDR range.
     */
    public function handle(AccessToken $token, string $name, array $projectIds, ?array $allowedIps, ?CarbonImmutable $expiresAt): AccessToken
    {
        Gate::authorize(Ability::ManageVault);

        $allowedIps = IpAllowlist::normalizeList($allowedIps);

        return DB::transaction(function () use ($token, $name, $projectIds, $allowedIps, $expiresAt): AccessToken {
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

            $result = $token->projects()->sync($projectIds);

            if ($result['attached'] !== [] || $result['detached'] !== []) {
                $changes['project_ids'] = [
                    'added' => array_map('strval', $result['attached']),
                    'removed' => array_map('strval', $result['detached']),
                ];
            }

            $this->auditLogger->record(AuditAction::TokenUpdated, $token, $changes);

            return $token;
        });
    }
}
