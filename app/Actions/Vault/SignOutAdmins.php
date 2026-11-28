<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SignOutAdmins
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * End the sessions of every admin except the actor and stop their remember me tokens. This needs the database
     * session driver: with another driver the sessions cannot be found by admin, and none are ended.
     *
     * @return int the number of sessions ended
     */
    public function handle(User $actor): int
    {
        Gate::forUser($actor)->authorize(Ability::ManageCompliance);

        return DB::transaction(function () use ($actor): int {
            User::query()->whereKeyNot($actor->getKey())->get()->each(
                fn (User $admin) => $admin->forceFill(['remember_token' => Str::random(60)])->saveQuietly(),
            );

            $count = config('session.driver') === 'database'
                ? DB::table(config('session.table', 'sessions'))->whereNotNull('user_id')->where('user_id', '!=', $actor->getKey())->delete()
                : 0;

            $this->auditLogger->record(AuditAction::AdminsSignedOut, null, ['sessions' => $count], $actor);

            return $count;
        });
    }
}
