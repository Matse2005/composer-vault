<?php

namespace App\Providers;

use App\Enums\AccessResult;
use App\Http\ApiError;
use App\Models\AccessToken;
use App\Models\User;
use App\Vault\Audit\AuditAuthenticationEvents;
use App\Vault\Authorization\Ability;
use App\Vault\Authorization\RolePermissions;
use App\Vault\Compliance\NotificationTargets;
use App\Vault\Crypto\EnvKeyProvider;
use App\Vault\Crypto\KeyProvider;
use App\Vault\Health\AccessReviewCheck;
use App\Vault\Health\AdminExistsCheck;
use App\Vault\Health\AuditChainCheck;
use App\Vault\Health\CspEnforcedCheck;
use App\Vault\Health\EnvFilePermissionsCheck;
use App\Vault\Health\HttpsCheck;
use App\Vault\Health\KeyVersionsCheck;
use App\Vault\Health\SecondFactorCheck;
use App\Vault\Health\SecurityLogChannelCheck;
use App\Vault\Health\SessionCheck;
use App\Vault\Health\SettingsCheck;
use App\Vault\Health\SodiumCheck;
use App\Vault\Health\SsoConfigurationCheck;
use App\Vault\Health\TrustedProxiesCheck;
use App\Vault\Health\VaultKeyCheck;
use App\Vault\RequestAttributes;
use App\Vault\Settings\ApplyVaultSettings;
use App\Vault\Settings\ChangeApproval;
use App\Vault\Settings\VaultSettings;
use App\Vault\Sso\SsoLogout;
use App\Vault\Tokens\AdminIpList;
use App\Vault\Tokens\TrustedProxyList;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KeyProvider::class, EnvKeyProvider::class);
        $this->app->scoped(VaultSettings::class);
        $this->app->scoped(RolePermissions::class);
        $this->app->scoped(ChangeApproval::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TrustedProxyList::parse(config('vault.trusted_proxies'));
        AdminIpList::parse(config('vault.admin.allowed_ips'));

        $this->configureDefaults();
        $this->configureAuthorization();
        $this->app->make(ApplyVaultSettings::class)->handle();
        $this->configureRateLimiting();
        $this->configureAuditListeners();
        $this->configureHealthChecks();
    }

    /**
     * Register the health checks in the order of the security status page and set the notification channels. A
     * channel is only used when its address or webhook is configured.
     */
    protected function configureHealthChecks(): void
    {
        Health::checks([
            EnvironmentCheck::new()->expectEnvironment('production'),
            DebugModeCheck::new()->expectedToBe(false),
            DatabaseCheck::new(),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(2),
            HttpsCheck::new(),
            SodiumCheck::new(),
            VaultKeyCheck::new(),
            KeyVersionsCheck::new(),
            EnvFilePermissionsCheck::new(),
            SessionCheck::new(),
            CspEnforcedCheck::new(),
            TrustedProxiesCheck::new(),
            SecondFactorCheck::new(),
            AuditChainCheck::new(),
            AdminExistsCheck::new(),
            SsoConfigurationCheck::new(),
            SecurityLogChannelCheck::new(),
            AccessReviewCheck::new(),
            SettingsCheck::new(),
        ]);

        if ($this->app->runningInConsole()) {
            NotificationTargets::applyToHealthConfig();
        }
    }

    /**
     * Define one gate per capability. A role holds a capability only when Ability lists it, and a deactivated admin
     * holds none.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('settings.any', fn (User $user): bool => $user->hasAbility(Ability::LogsView) || collect(Ability::all())->contains(fn (string $permission): bool => str_starts_with($permission, 'settings.') && $user->hasAbility($permission)));

        foreach ([...Ability::all(), Ability::ApprovalManage] as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->hasAbility($ability));
        }
    }

    /**
     * Audit the authentication events of the admins.
     */
    protected function configureAuditListeners(): void
    {
        Event::listen(Login::class, [AuditAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Logout::class, [SsoLogout::class, 'capture']);
        Event::listen(TwoFactorAuthenticationConfirmed::class, [AuditAuthenticationEvents::class, 'handleTwoFactorConfirmed']);
        Event::listen(TwoFactorAuthenticationDisabled::class, [AuditAuthenticationEvents::class, 'handleTwoFactorDisabled']);
        Event::listen(PasskeyRegistered::class, [AuditAuthenticationEvents::class, 'handlePasskeyRegistered']);
        Event::listen(PasskeyDeleted::class, [AuditAuthenticationEvents::class, 'handlePasskeyDeleted']);
    }

    /**
     * Define the per token rate limit of the API. It runs after the token middleware, so the key is the token ID.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('sso', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));

        RateLimiter::for('vault-api', function (Request $request): Limit {
            $token = $request->attributes->get(RequestAttributes::ACCESS_TOKEN);

            return Limit::perMinute(app(VaultSettings::class)->apiRateLimitPerMinute())
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
