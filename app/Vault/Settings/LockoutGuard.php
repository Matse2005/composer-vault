<?php

namespace App\Vault\Settings;

use App\Enums\AdminRole;
use App\Exceptions\Vault\LockoutGuardException;
use App\Models\AuditEntry;
use App\Models\User;
use App\Vault\Sso\SsoTestSignIn;
use App\Vault\Tokens\AdminIpList;
use App\Vault\Tokens\IpAllowlist;
use Illuminate\Validation\ValidationException;
use JeffersonGoncalves\LaravelOidc\Services\OidcDiscoveryService;
use Throwable;

/**
 * Guards for the settings that can lock people out or delete evidence. Every guard either refuses the change or asks
 * for a confirmation. The recovery command `vault:settings` does not use it, because recovery must always work.
 */
class LockoutGuard
{
    public const RETENTION_WORD = 'DELETE';

    /**
     * @param  array<string, mixed>  $new  the normalised values about to be written
     * @param  array<string, mixed>  $current  the values that apply now
     * @param  array<string, mixed>  $confirmations  setting key to true (confirmed) or the word that was typed
     *
     * @throws LockoutGuardException
     * @throws ValidationException
     */
    public function assert(array $new, array $current, User $actor, array $confirmations): void
    {
        if (array_key_exists('admin.allowed_ips', $new)) {
            $this->assertAllowlistKeepsActor((string) ($new['admin.allowed_ips'] ?? ''), (string) ($current['admin.allowed_ips'] ?? ''), $confirmations);
        }

        if (array_key_exists('sso.issuer', $new) && filled($new['sso.issuer']) && $new['sso.issuer'] !== ($current['sso.issuer'] ?? null)) {
            $this->assertDiscoveryWorks((string) $new['sso.issuer']);
        }

        if (($new['sso.enforce'] ?? false) === true && ($current['sso.enforce'] ?? false) !== true) {
            $this->assertEnforcementIsSafe($actor);
        }

        if (array_key_exists('retention.audit_log_days', $new)) {
            $this->assertRetentionConfirmed((int) $new['retention.audit_log_days'], (int) ($current['retention.audit_log_days'] ?? 0), $confirmations);
        }
    }

    /**
     * The new allowlist must contain the address of the admin who makes the change, unless they confirm that they
     * will lose access.
     *
     * @param  array<string, mixed>  $confirmations
     */
    private function assertAllowlistKeepsActor(string $new, string $old, array $confirmations): void
    {
        $list = AdminIpList::parse($new);

        if ($list === [] || $new === $old || IpAllowlist::allows($list, (string) request()->ip()) || ($confirmations['admin.allowed_ips'] ?? false) === true) {
            return;
        }

        throw new LockoutGuardException('admin.allowed_ips', __('Your own address, :ip, is not in this list. If you save it, you lose access to the admin interface from here.', ['ip' => (string) request()->ip()]));
    }

    private function assertDiscoveryWorks(string $issuer): void
    {
        try {
            app(OidcDiscoveryService::class)->discover($issuer);
        } catch (Throwable) {
            throw ValidationException::withMessages(['sso.issuer' => __('The identity provider did not answer discovery at this issuer. Nothing was saved.')]);
        }
    }

    /**
     * Enforcement needs a successful test sign-in by this admin in the last ten minutes, and at least one break-glass
     * Owner with a confirmed second factor, so the vault stays reachable if the provider breaks.
     */
    private function assertEnforcementIsSafe(User $actor): void
    {
        if (! app(SsoTestSignIn::class)->passedRecentlyBy($actor)) {
            throw ValidationException::withMessages(['sso.enforce' => __('Test the sign-in first: use "Test sign-in" and sign in successfully, then turn enforcement on within 10 minutes.')]);
        }

        $breakGlass = User::query()->active()->where('is_break_glass', true)->where('role', AdminRole::Owner->value)->whereNotNull('two_factor_confirmed_at')->exists();

        if (! $breakGlass) {
            throw ValidationException::withMessages(['sso.enforce' => __('Mark at least one Owner as break-glass, with an authenticator app set up, before enforcing single sign-on.')]);
        }
    }

    /**
     * Lowering the audit log retention below the oldest entry makes the next prune delete entries: show how many and
     * ask for a typed word.
     *
     * @param  array<string, mixed>  $confirmations
     */
    private function assertRetentionConfirmed(int $new, int $old, array $confirmations): void
    {
        if ($new >= $old) {
            return;
        }

        $affected = AuditEntry::query()->where('created_at', '<', now()->subDays($new))->count();

        if ($affected === 0 || ($confirmations['retention.audit_log_days'] ?? null) === self::RETENTION_WORD) {
            return;
        }

        throw new LockoutGuardException('retention.audit_log_days', __('The next prune would delete :count audit entries. Type :word to confirm.', ['count' => $affected, 'word' => self::RETENTION_WORD]), self::RETENTION_WORD);
    }
}
