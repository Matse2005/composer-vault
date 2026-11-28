<?php

namespace App\Console\Commands;

use App\Exceptions\Vault\AuditPruneRefusedException;
use App\Models\AccessLog;
use App\Models\Invitation;
use App\Vault\Audit\AuditPruner;
use App\Vault\Settings\VaultSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('vault:prune')]
#[Description('Remove data that is older than the retention period: access log, audit log, invitations and expired sessions')]
class PruneVault extends Command
{
    /**
     * Prune every kind of data. The audit log goes last and is refused, loudly, when its chain is broken: the other
     * kinds are already done by then and the command fails.
     */
    public function handle(VaultSettings $settings, AuditPruner $auditPruner): int
    {
        $accessLogs = AccessLog::query()->where('created_at', '<', now()->subDays($settings->accessLogRetentionDays()))->delete();

        $cutoff = now()->subDays($settings->invitationRetentionDays());
        $invitations = Invitation::query()
            ->where(fn ($query) => $query->where('expires_at', '<', $cutoff)->orWhere('accepted_at', '<', $cutoff)->orWhere('revoked_at', '<', $cutoff))
            ->delete();

        $sessions = config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())->delete()
            : 0;

        $this->components->info("Removed {$accessLogs} access log row(s), {$invitations} invitation(s) and {$sessions} expired session(s).");

        try {
            $audit = $auditPruner->prune($settings->auditLogRetentionDays());
        } catch (AuditPruneRefusedException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Removed {$audit} audit entr".($audit === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
