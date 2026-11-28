<?php

namespace App\Vault;

use App\Enums\AccessResult;
use App\Enums\AlertRule;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Project;
use App\Vault\Compliance\SecurityAlerts;
use App\Vault\Compliance\SecurityEventStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes the access log row of an API request from the request attributes. The bearer value, or any part of
 * it, is never written: the token is only referenced by the ID of its row.
 */
class AccessLogger
{
    private const RECENT_IPS = 20;

    private const MAX_SLUG_LENGTH = 64;

    private const MAX_USER_AGENT_LENGTH = 512;

    /**
     * Count a failed attempt, and notice a token that works from an address it never used before.
     */
    private function raiseAlerts(AccessLog $log, mixed $token): void
    {
        try {
            $alerts = app(SecurityAlerts::class);

            if ($log->result !== AccessResult::Ok) {
                $alerts->failedAttempt($log->ip);

                return;
            }

            if ($token instanceof AccessToken && $token->allowed_ips === null) {
                $this->noticeNewAddress($token, $log->ip, $alerts);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function noticeNewAddress(AccessToken $token, string $ip, SecurityAlerts $alerts): void
    {
        $known = array_values((array) $token->recent_ips);

        if (in_array($ip, $known, true)) {
            return;
        }

        $token->forceFill(['recent_ips' => array_slice([...$known, $ip], -self::RECENT_IPS)])->saveQuietly();

        if ($known !== []) {
            $alerts->send(AlertRule::TokenFromNewIp, $token->getKey().':'.$ip, "Token {$token->token_prefix} (#{$token->getKey()}) was used from a new address: {$ip}.");
        }
    }

    /**
     * Record the request. A failure to write is reported and does not change the response.
     */
    public function record(Request $request): void
    {
        try {
            $token = $request->attributes->get(RequestAttributes::ACCESS_TOKEN);
            $project = $request->attributes->get(RequestAttributes::PROJECT);
            $result = $request->attributes->get(RequestAttributes::ACCESS_RESULT);
            $slug = $request->attributes->get(RequestAttributes::REQUESTED_SLUG);
            $userAgent = $request->userAgent();

            $log = (new AccessLog)->forceFill([
                'access_token_id' => $token instanceof AccessToken ? $token->getKey() : null,
                'project_id' => $project instanceof Project ? $project->getKey() : null,
                'requested_slug' => is_string($slug) ? mb_substr($slug, 0, self::MAX_SLUG_LENGTH) : null,
                'ip' => (string) $request->ip(),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH),
                'result' => $result instanceof AccessResult ? $result : AccessResult::UnknownToken,
            ]);

            DB::transaction(function () use ($log, $token, $project): void {
                $log->save();

                DB::afterCommit(fn () => $this->raiseAlerts($log, $token));

                DB::afterCommit(fn () => app(SecurityEventStream::class)->access(
                    $log,
                    $token instanceof AccessToken ? $token->token_prefix : null,
                    $project instanceof Project ? $project->slug : null,
                ));
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
