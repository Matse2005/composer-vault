<?php

namespace App\Console\Commands;

use App\Actions\Vault\DeactivateAdmin;
use App\Exceptions\Vault\LastOwnerException;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('vault:deactivate-inactive-admins')]
#[Description('Deactivate admins who have not logged in for the configured number of days')]
class DeactivateInactiveAdmins extends Command
{
    /**
     * Deactivate every active admin whose last login (or creation, when there was none) is older than the limit.
     * Break-glass accounts and the last active Owner are skipped.
     */
    public function handle(DeactivateAdmin $deactivateAdmin, VaultSettings $settings): int
    {
        $limit = now()->subDays($settings->inactiveDeactivateDays());
        $deactivated = 0;

        $candidates = User::query()
            ->active()
            ->where('is_break_glass', false)
            ->whereRaw('coalesce(last_login_at, created_at) < ?', [$limit])
            ->get();

        foreach ($candidates as $admin) {
            try {
                $deactivateAdmin->handleInactive($admin);
                $deactivated++;
            } catch (LastOwnerException) {
                $this->components->warn('Skipped the last active Owner.');
            }
        }

        $this->components->info("Deactivated {$deactivated} inactive admin(s).");

        return self::SUCCESS;
    }
}
