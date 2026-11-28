<?php

namespace App\Vault\Sso;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Exceptions\Vault\LastOwnerException;
use App\Exceptions\Vault\SsoRejectedException;
use App\Models\Invitation;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\OwnerGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JeffersonGoncalves\LaravelOidc\Data\OidcUser;
use JeffersonGoncalves\LaravelOidc\Exceptions\DiscoveryFailedException;
use JeffersonGoncalves\LaravelOidc\Exceptions\JwksFetchException;
use JeffersonGoncalves\LaravelOidc\Exceptions\OidcException;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * The callback of the authorization code flow, in the order of the plan: state, code exchange, ID token, email and
 * domain, role, user, deactivation, role change, second factor. Any failure is a SsoRejectedException with a reason
 * code; nothing from the identity provider is stored except the issuer and subject on the user.
 */
class SsoSignIn
{
    public const STARTED_KEY = 'vault.sso.started_at';

    private const LIFETIME_MINUTES = 10;

    public function __construct(
        private SsoConfig $config,
        private SsoProvider $provider,
        private RoleResolver $roles,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Start a sign-in: the browser goes to the authorization endpoint with state, nonce and a PKCE challenge, which
     * the driver keeps in the session. The time is kept as well, because a started sign-in is only good for ten
     * minutes.
     *
     * @throws SsoRejectedException when the provider cannot be reached
     */
    public function begin(Request $request): RedirectResponse
    {
        try {
            $response = $this->provider->driver()->redirect();
        } catch (Throwable) {
            throw new SsoRejectedException(SsoRejectedException::DISCOVERY_FAILED);
        }

        $request->session()->put(self::STARTED_KEY, now()->getTimestamp());

        return $response;
    }

    /**
     * @throws SsoRejectedException
     */
    public function handle(Request $request): SsoSignInResult
    {
        if (! $this->config->isActive()) {
            throw new SsoRejectedException(SsoRejectedException::NOT_CONFIGURED);
        }

        $claims = $this->claimsOf($request);
        $email = $this->verifiedEmail($claims);
        $role = $this->roles->resolve($claims);

        if ($role === null && $this->config->hasRoleMapping()) {
            throw new SsoRejectedException(SsoRejectedException::NO_ROLE);
        }

        $user = DB::transaction(fn (): User => $this->resolveUser($claims, $email, $role));

        if ($user->isDeactivated()) {
            throw new SsoRejectedException(SsoRejectedException::DEACTIVATED);
        }

        $this->applyRole($user, $role);

        $mfaTrusted = $this->idpVouchedForMfa($claims);

        if (! $mfaTrusted && $user->two_factor_confirmed_at === null && $user->hasPasskeysEnabled()) {
            throw new SsoRejectedException(SsoRejectedException::SECOND_FACTOR_REQUIRED);
        }

        return new SsoSignInResult($user, mfaSatisfied: $mfaTrusted, needsLocalChallenge: ! $mfaTrusted && $user->two_factor_confirmed_at !== null);
    }

    /**
     * Check the state, exchange the code and validate the ID token through the driver, and return the claims of the
     * token. The access and refresh token of the driver are never read and never stored.
     *
     * @return array<string, mixed>
     *
     * @throws SsoRejectedException
     */
    private function claimsOf(Request $request): array
    {
        $session = $request->session();
        $startedAt = $session->pull(self::STARTED_KEY);

        if (! $session->has('state') || $startedAt === null) {
            $session->forget('state');

            throw new SsoRejectedException(SsoRejectedException::STATE_MISSING);
        }

        if ($startedAt < now()->subMinutes(self::LIFETIME_MINUTES)->getTimestamp()) {
            $session->forget('state');

            throw new SsoRejectedException(SsoRejectedException::STATE_EXPIRED);
        }

        if ($request->query('error') !== null) {
            $session->forget('state');

            throw new SsoRejectedException(SsoRejectedException::PROVIDER_ERROR);
        }

        if (! is_string($request->query('code')) || $request->query('code') === '') {
            $session->forget('state');

            throw new SsoRejectedException(SsoRejectedException::CODE_MISSING);
        }

        try {
            $user = $this->provider->driver()->user();
        } catch (InvalidStateException) {
            throw new SsoRejectedException(SsoRejectedException::STATE_MISMATCH);
        } catch (DiscoveryFailedException|JwksFetchException) {
            throw new SsoRejectedException(SsoRejectedException::DISCOVERY_FAILED);
        } catch (OidcException) {
            throw new SsoRejectedException(SsoRejectedException::INVALID_ID_TOKEN);
        } catch (Throwable) {
            throw new SsoRejectedException(SsoRejectedException::TOKEN_EXCHANGE_FAILED);
        }

        return $user instanceof OidcUser ? $user->idTokenClaims : [];
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws SsoRejectedException
     */
    private function verifiedEmail(array $claims): string
    {
        $email = $claims['email'] ?? null;

        if (! is_string($email) || ! str_contains($email, '@')) {
            throw new SsoRejectedException(SsoRejectedException::EMAIL_MISSING);
        }

        if (($claims['email_verified'] ?? false) !== true) {
            throw new SsoRejectedException(SsoRejectedException::EMAIL_UNVERIFIED);
        }

        $email = mb_strtolower(trim($email));
        $domains = $this->config->allowedEmailDomains();

        if ($domains !== [] && ! in_array(Str::after($email, '@'), $domains, true)) {
            throw new SsoRejectedException(SsoRejectedException::DOMAIN_NOT_ALLOWED);
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws SsoRejectedException
     */
    private function resolveUser(array $claims, string $email, ?AdminRole $role): User
    {
        $issuer = $this->config->issuer();
        $subject = (string) $claims['sub'];

        $user = User::query()->where('sso_issuer', $issuer)->where('sso_subject', $subject)->lockForUpdate()->first();

        if ($user !== null) {
            return $user;
        }

        $byEmail = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();

        if ($byEmail !== null) {
            if ($byEmail->isDeactivated()) {
                throw new SsoRejectedException(SsoRejectedException::DEACTIVATED);
            }

            if ($byEmail->sso_subject !== null) {
                throw new SsoRejectedException(SsoRejectedException::NOT_PROVISIONED);
            }

            $byEmail->forceFill(['sso_issuer' => $issuer, 'sso_subject' => $subject])->save();
            $this->auditLogger->record(AuditAction::SsoAccountLinked, $byEmail, [], null);

            return $byEmail;
        }

        $invitation = Invitation::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if ($invitation !== null) {
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $this->provision($claims, $email, $role ?? $invitation->role, $issuer, $subject, 'invitation');
        }

        if (! $this->config->autoProvision() || $role === null) {
            throw new SsoRejectedException(SsoRejectedException::NOT_PROVISIONED);
        }

        return $this->provision($claims, $email, $role, $issuer, $subject);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function provision(array $claims, string $email, AdminRole $role, string $issuer, string $subject, string $source = 'sso'): User
    {
        $name = $claims['name'] ?? null;

        $user = (new User)->forceFill([
            'name' => is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(64),
            'email_verified_at' => now(),
            'role' => $role,
            'sso_issuer' => $issuer,
            'sso_subject' => $subject,
        ]);
        $user->save();

        $this->auditLogger->record(AuditAction::AdminProvisioned, $user, ['role' => $role, 'source' => $source], null);

        return $user;
    }

    /**
     * Follow a change of role at the identity provider. The last active Owner is never demoted this way.
     */
    private function applyRole(User $user, ?AdminRole $role): void
    {
        if ($role === null || $user->role === $role) {
            return;
        }

        DB::transaction(function () use ($user, $role): void {
            try {
                if ($role !== AdminRole::Owner) {
                    OwnerGuard::assertAnotherActiveOwnerExists($user);
                }
            } catch (LastOwnerException) {
                return;
            }

            $old = $user->role;
            $user->forceFill(['role' => $role])->save();

            $this->auditLogger->record(AuditAction::AdminRoleChanged, $user, ['old_role' => $old, 'new_role' => $role, 'source' => 'sso'], null);
        });
    }

    /**
     * Determine whether the provider says the sign-in used more than one factor, when the owner trusts that.
     *
     * @param  array<string, mixed>  $claims
     */
    private function idpVouchedForMfa(array $claims): bool
    {
        if (! $this->config->trustIdpMfa()) {
            return false;
        }

        $amr = $claims['amr'] ?? [];

        if (is_array($amr) && in_array('mfa', $amr, true)) {
            return true;
        }

        $acr = $claims['acr'] ?? null;

        return is_string($acr) && in_array($acr, $this->config->mfaAcrValues(), true);
    }
}
