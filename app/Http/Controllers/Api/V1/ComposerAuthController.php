<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccessResult;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Models\Project;
use App\Vault\ComposerAuthBuilder;
use App\Vault\RequestAttributes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

class ComposerAuthController extends Controller
{
    public function __construct(private ComposerAuthBuilder $composerAuthBuilder) {}

    /**
     * Return the Composer credentials of the project that the authenticated token is linked to.
     *
     * Both queries run on every request, and an unknown project and a project the token is not linked to
     * produce the same response through the same code path. The project is never bound by route model binding,
     * because binding would answer before authentication and reveal which slugs exist.
     */
    public function __invoke(Request $request, string $slug): JsonResponse
    {
        /** @var AccessToken $token */
        $token = $request->attributes->get(RequestAttributes::ACCESS_TOKEN);

        $linkedProject = $token->projects()->where('slug', $slug)->first();
        $projectExists = Project::query()->where('slug', $slug)->exists();

        if ($linkedProject === null) {
            $request->attributes->set(RequestAttributes::ACCESS_RESULT, $projectExists ? AccessResult::ProjectDenied : AccessResult::UnknownProject);
            $request->attributes->set(RequestAttributes::REQUESTED_SLUG, $slug);

            return ApiError::response(404, ApiError::NOT_FOUND);
        }

        $request->attributes->set(RequestAttributes::PROJECT, $linkedProject);
        $request->attributes->set(RequestAttributes::ACCESS_RESULT, AccessResult::Ok);

        try {
            $body = $this->composerAuthBuilder->build($linkedProject);
        } catch (DecryptionFailedException $exception) {
            report($exception);

            return ApiError::response(500, ApiError::SERVER_ERROR);
        }

        return response()->json($body === [] ? new stdClass : $body);
    }
}
