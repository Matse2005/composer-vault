<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\TokenIssuingFailedException;
use App\Exceptions\Vault\TokenPolicyException;
use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Tokens\IpAllowlist;
use App\Vault\Tokens\IssuedToken;
use App\Vault\Tokens\TokenIssuer;
use App\Vault\Tokens\TokenPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class IssueAccessToken
{
    public function __construct(
        private TokenIssuer $tokenIssuer,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Issue an access token for one project. A token belongs to exactly one project and can fetch only that project;
     * a server that deploys two projects gets two tokens. The plain token is only in the returned value.
     *
     * @param  array<int, string>|null  $allowedIps  IP addresses and CIDR ranges. Null or empty means no restriction.
     *
     * @throws InvalidArgumentException When an allowlist entry is not an IP address or CIDR range.
     * @throws TokenIssuingFailedException
     * @throws TokenPolicyException When a maximum lifetime is set and the expiry is missing or too far away.
     */
    public function handle(Project $project, string $name, ?array $allowedIps, ?CarbonImmutable $expiresAt, ?User $creator): IssuedToken
    {
        Gate::authorize(Ability::TokensManage);
        TokenPolicy::assertAllows($expiresAt);

        $allowedIps = IpAllowlist::normalizeList($allowedIps);

        return DB::transaction(function () use ($project, $name, $allowedIps, $expiresAt, $creator): IssuedToken {
            $token = (new AccessToken)->forceFill([
                'project_id' => $project->getKey(),
                'name' => $name,
                'allowed_ips' => $allowedIps,
                'expires_at' => $expiresAt,
                'created_by' => $creator?->getKey(),
            ]);

            $issued = $this->tokenIssuer->issue($token);

            $this->auditLogger->record(AuditAction::TokenIssued, $token, [
                'name' => $token->name,
                'token_prefix' => $token->token_prefix,
                'project_id' => $project->getKey(),
                'allowed_ips' => $allowedIps,
                'expires_at' => $expiresAt,
            ], $creator);

            return $issued;
        });
    }
}
