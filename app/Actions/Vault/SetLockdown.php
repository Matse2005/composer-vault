<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\User;
use App\Models\VaultSetting;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SetLockdown
{
    public const KEY = 'incident.lockdown';

    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Limit the admin interface to Owners until it is lifted again. Other roles get a 403 on every page.
     */
    public function handle(bool $on, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageCompliance);

        DB::transaction(function () use ($on, $actor): void {
            if ($on) {
                VaultSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => true]);
            } else {
                VaultSetting::query()->whereKey(self::KEY)->delete();
            }

            $this->auditLogger->record(AuditAction::LockdownChanged, null, ['lockdown' => $on], $actor);
        });
    }

    public static function isOn(): bool
    {
        return VaultSetting::query()->whereKey(self::KEY)->exists();
    }
}
