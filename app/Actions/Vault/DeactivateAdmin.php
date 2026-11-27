<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\CannotDeactivateAdminException;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeactivateAdmin
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Deactivate an admin: the admin can no longer log in, open sessions end and the remember me token stops working.
     * The account is kept so that audit entries stay readable. Nothing happens for an admin who is deactivated already.
     *
     * @throws CannotDeactivateAdminException when the admin is the actor or the last active admin
     */
    public function handle(User $admin, User $actor): void
    {
        if ($admin->is($actor)) {
            throw CannotDeactivateAdminException::yourself();
        }

        DB::transaction(function () use ($admin, $actor): void {
            $admin->refresh();

            if ($admin->isDeactivated()) {
                return;
            }

            $otherActiveAdmins = User::query()->active()->whereKeyNot($admin->getKey())->lockForUpdate()->exists();

            if (! $otherActiveAdmins) {
                throw CannotDeactivateAdminException::lastActiveAdmin();
            }

            $admin->forceFill([
                'deactivated_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $admin->getKey())->delete();
            }

            $this->auditLogger->record(AuditAction::AdminDeactivated, $admin, [], $actor);
        });
    }
}
