<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\TokenIssuingFailedException;
use App\Models\AccessToken;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Tokens\IpAllowlist;
use App\Vault\Tokens\IssuedToken;
use App\Vault\Tokens\TokenIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IssueAccessToken
{
    public function __construct(
        private TokenIssuer $tokenIssuer,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Issue an access token for the given projects. The plain token is only in the returned value.
     *
     * @param  array<int, string>  $projectIds
     * @param  array<int, string>|null  $allowedIps  IP addresses and CIDR ranges. Null or empty means no restriction.
     *
     * @throws InvalidArgumentException When an allowlist entry is not an IP address or CIDR range.
     * @throws TokenIssuingFailedException
     */
    public function handle(string $name, array $projectIds, ?array $allowedIps, ?CarbonImmutable $expiresAt, ?User $creator): IssuedToken
    {
        $allowedIps = $this->validatedAllowlist($allowedIps);

        return DB::transaction(function () use ($name, $projectIds, $allowedIps, $expiresAt, $creator): IssuedToken {
            $token = (new AccessToken)->forceFill([
                'name' => $name,
                'allowed_ips' => $allowedIps,
                'expires_at' => $expiresAt,
                'created_by' => $creator?->getKey(),
            ]);

            $issued = $this->tokenIssuer->issue($token);

            $token->projects()->sync($projectIds);

            $this->auditLogger->record(AuditAction::TokenIssued, $token, [
                'name' => $token->name,
                'token_prefix' => $token->token_prefix,
                'project_ids' => array_values($projectIds),
                'allowed_ips' => $allowedIps,
                'expires_at' => $expiresAt,
            ], $creator);

            return $issued;
        });
    }

    /**
     * @param  array<int, string>|null  $allowedIps
     * @return array<int, string>|null
     *
     * @throws InvalidArgumentException
     */
    private function validatedAllowlist(?array $allowedIps): ?array
    {
        if ($allowedIps === null || $allowedIps === []) {
            return null;
        }

        foreach ($allowedIps as $entry) {
            if (! IpAllowlist::isValidEntry($entry)) {
                throw new InvalidArgumentException('The IP allowlist contains an entry that is not an IP address or CIDR range.');
            }
        }

        return array_values($allowedIps);
    }
}
