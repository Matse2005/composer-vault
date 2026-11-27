<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ComposerAuthController extends Controller
{
    /**
     * Placeholder until ticket 3.4: no credentials are served before the token middleware exists.
     */
    public function __invoke(string $slug): never
    {
        throw new NotFoundHttpException;
    }
}
