<?php

namespace App\Vault;

use App\Enums\AccessResult;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes the access log row of an API request from the request attributes. The bearer value, or any part of
 * it, is never written: the token is only referenced by the ID of its row.
 */
class AccessLogger
{
    private const MAX_SLUG_LENGTH = 64;

    private const MAX_USER_AGENT_LENGTH = 512;

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

            DB::transaction(fn () => $log->save());
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
