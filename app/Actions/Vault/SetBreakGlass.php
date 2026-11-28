<?php

namespace App\Actions\Vault;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Exceptions\Vault\BreakGlassNotAllowedException;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SetBreakGlass
{
    public const MAXIMUM = 2;

    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Mark an Owner as a break-glass account, or take the mark away. A break-glass Owner keeps the password and the
     * local second factor when single sign-on is enforced, so the company can get in when the provider is down.
     *
     * @throws BreakGlassNotAllowedException when the admin is not an active Owner, or two accounts are marked already
     */
    public function handle(User $admin, bool $breakGlass, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageAdmins);

        DB::transaction(function () use ($admin, $breakGlass, $actor): void {
            $admin->refresh();

            if ($admin->is_break_glass === $breakGlass) {
                return;
            }

            if ($breakGlass) {
                if ($admin->role !== AdminRole::Owner || $admin->isDeactivated()) {
                    throw BreakGlassNotAllowedException::notAnOwner();
                }

                if (count(User::query()->where('is_break_glass', true)->lockForUpdate()->pluck('id')->all()) >= self::MAXIMUM) {
                    throw BreakGlassNotAllowedException::tooMany();
                }
            }

            $admin->forceFill(['is_break_glass' => $breakGlass])->save();

            $this->auditLogger->record(AuditAction::BreakGlassChanged, $admin, ['break_glass' => $breakGlass], $actor);
        });
    }
}
