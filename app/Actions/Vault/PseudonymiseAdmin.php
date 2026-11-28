<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\PseudonymisationRefusedException;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PseudonymiseAdmin
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Replace the personal data of a deactivated admin: the name and email become a pseudonym, and the password,
     * two-factor data, passkeys, sessions and single sign-on link are removed. The account and its audit entries stay,
     * so entries still resolve to the pseudonym. IP addresses in audit entries are part of the hash chain and are not
     * changed; they leave the vault through retention.
     *
     * @throws PseudonymisationRefusedException when the admin is not deactivated
     */
    public function handle(User $admin, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageCompliance);

        DB::transaction(function () use ($admin, $actor): void {
            $admin->refresh();

            if (! $admin->isDeactivated()) {
                throw PseudonymisationRefusedException::stillActive();
            }

            if ($admin->pseudonymised_at !== null) {
                return;
            }

            $admin->passkeys()->delete();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $admin->getKey())->delete();
            }

            $admin->forceFill([
                'name' => 'Former admin '.substr((string) Str::ulid(), -8),
                'email' => 'deleted-'.$admin->getKey().'@invalid',
                'password' => Str::random(64),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => null,
                'sso_issuer' => null,
                'sso_subject' => null,
                'is_break_glass' => false,
                'pseudonymised_at' => now(),
            ])->save();

            $this->auditLogger->record(AuditAction::AdminPseudonymised, $admin, [], $actor);
        });
    }
}
