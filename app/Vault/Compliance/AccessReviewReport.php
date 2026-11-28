<?php

namespace App\Vault\Compliance;

use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Illuminate\Database\Eloquent\Collection;

/**
 * What an access review looks at: the active admins, the tokens that need a closer look and the credentials that are
 * due for rotation. It reads and never changes anything.
 */
class AccessReviewReport
{
    public const EXPIRING_SOON_DAYS = 14;

    public const UNUSED_DAYS = 90;

    public function __construct(private VaultSettings $settings) {}

    /**
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::query()->withCount('passkeys')->orderByRaw('deactivated_at is not null')->orderBy('name')->get();
    }

    /**
     * Tokens that are not revoked, with the reasons they need a look. A token can have several.
     *
     * @return list<array{token: AccessToken, reasons: list<string>}>
     */
    public function tokens(): array
    {
        $max = $this->settings->tokenMaxLifetimeDays();
        $rows = [];

        foreach (AccessToken::query()->whereNull('revoked_at')->orderBy('name')->get() as $token) {
            $reasons = [];

            if ($token->expires_at === null) {
                $reasons[] = 'No expiry';
            } elseif ($token->expires_at->isFuture() && $token->expires_at->lte(now()->addDays(self::EXPIRING_SOON_DAYS))) {
                $reasons[] = 'Expires within '.self::EXPIRING_SOON_DAYS.' days';
            }

            if ($max !== null && ($token->expires_at === null || $token->expires_at->gt(now()->addDays($max)->endOfDay()))) {
                $reasons[] = "Expiry beyond the policy of {$max} days";
            }

            if (($token->last_used_at ?? $token->created_at)?->lt(now()->subDays(self::UNUSED_DAYS))) {
                $reasons[] = 'Not used for '.self::UNUSED_DAYS.' days';
            }

            if ($token->allowed_ips === null || $token->allowed_ips === []) {
                $reasons[] = 'No IP allowlist';
            }

            if ($reasons !== []) {
                $rows[] = ['token' => $token, 'reasons' => $reasons];
            }
        }

        return $rows;
    }

    /**
     * Credentials whose secret has not changed for longer than the rotation policy. Empty when there is no policy.
     *
     * @return Collection<int, Credential>
     */
    public function credentialsDueForRotation(): Collection
    {
        $days = $this->settings->credentialRotationDays();

        if ($days === null) {
            return new Collection;
        }

        return Credential::query()->where('secret_changed_at', '<', now()->subDays($days))->orderBy('secret_changed_at')->get();
    }

    /**
     * The counts stored with a completed review.
     *
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        $admins = $this->admins();

        return [
            'admins_active' => $admins->whereNull('deactivated_at')->count(),
            'admins_deactivated' => $admins->whereNotNull('deactivated_at')->count(),
            'admins_without_second_factor' => $admins->whereNull('deactivated_at')->reject(fn (User $admin): bool => $admin->hasSecondFactor())->count(),
            'tokens_flagged' => count($this->tokens()),
            'credentials_due_for_rotation' => $this->credentialsDueForRotation()->count(),
        ];
    }
}
