<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AuthenticateAccessToken;
use App\Http\Middleware\BlockExcessiveFailures;
use App\Http\Middleware\EnsureSecondFactor;
use App\Http\Middleware\RecordVaultAccess;
use App\Http\Middleware\RestrictAdminIps;
use App\Http\Middleware\SetNoStoreHeaders;
use App\Http\Middleware\TrustVaultProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustVaultProxies::class);

        $middleware->alias(['second-factor' => EnsureSecondFactor::class]);

        // First in the web group, so a blocked address never starts a session or reaches the application.
        $middleware->prependToGroup('web', RestrictAdminIps::class);

        $middleware->group('vault-api', [
            SetNoStoreHeaders::class,
            RecordVaultAccess::class,
            BlockExcessiveFailures::class,
            AuthenticateAccessToken::class,
            'throttle:vault-api',
        ]);

        // Laravel sorts route middleware by its priority list, and the rate limiter is on that list. Listing the
        // vault middleware before it keeps the documented order: the limiter must run after the token is known.
        foreach ([SetNoStoreHeaders::class, RecordVaultAccess::class, BlockExcessiveFailures::class, AuthenticateAccessToken::class] as $vaultMiddleware) {
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: $vaultMiddleware);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render((new ApiExceptionRenderer)(...));
    })->create();
