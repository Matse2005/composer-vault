<?php

namespace App\Providers;

use App\Enums\AccessResult;
use App\Http\ApiError;
use App\Models\AccessToken;
use App\Vault\Crypto\EnvKeyProvider;
use App\Vault\Crypto\KeyProvider;
use App\Vault\RequestAttributes;
use App\Vault\Tokens\TrustedProxyList;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KeyProvider::class, EnvKeyProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TrustedProxyList::parse(config('vault.trusted_proxies'));

        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Define the per token rate limit of the API. It runs after the token middleware, so the key is the token ID.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('vault-api', function (Request $request): Limit {
            $token = $request->attributes->get(RequestAttributes::ACCESS_TOKEN);

            return Limit::perMinute((int) config('vault.api.rate_limit_per_minute'))
                ->by('token:'.($token instanceof AccessToken ? $token->getKey() : ''))
                ->response(function (Request $request, array $headers): JsonResponse {
                    $request->attributes->set(RequestAttributes::ACCESS_RESULT, AccessResult::RateLimited);

                    return ApiError::response(429, ApiError::TOO_MANY_REQUESTS, $headers);
                });
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
