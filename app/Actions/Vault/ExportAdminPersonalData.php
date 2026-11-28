<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ExportAdminPersonalData
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Collect the personal data the vault holds about an admin, for the admin themselves or for an Owner. Secrets are
     * not part of it: no password hash, no two-factor secret or recovery codes, no passkey credential.
     *
     * @return array<string, mixed>
     */
    public function handle(User $subject, User $actor): array
    {
        if (! $subject->is($actor)) {
            Gate::forUser($actor)->authorize(Ability::ComplianceManage);
        }

        $data = [
            'exported_at' => $this->time(now()),
            'profile' => [
                'id' => $subject->id,
                'name' => $subject->name,
                'email' => $subject->email,
                'role' => $subject->role->value,
                'break_glass' => $subject->is_break_glass,
                'created_at' => $this->time($subject->created_at),
                'last_login_at' => $this->time($subject->last_login_at),
                'deactivated_at' => $this->time($subject->deactivated_at),
                'sso_linked' => $subject->sso_subject !== null,
            ],
            'second_factor' => [
                'authenticator_app' => $subject->two_factor_confirmed_at !== null,
                'passkeys' => $subject->passkeys()->orderBy('id')->get(['name', 'created_at', 'last_used_at'])
                    ->map(fn ($passkey): array => ['name' => $passkey->name, 'created_at' => $this->time($passkey->created_at), 'last_used_at' => $this->time($passkey->last_used_at)])->all(),
            ],
            'sessions' => $this->sessions($subject),
            'audit_entries' => AuditEntry::query()
                ->where('causer_type', User::class)
                ->where('causer_id', $subject->getKey())
                ->orderBy('sequence')
                ->get(['sequence', 'event', 'ip', 'created_at'])
                ->map(fn (AuditEntry $entry): array => ['sequence' => $entry->sequence, 'time' => $this->time($entry->created_at), 'event' => $entry->event, 'ip' => $entry->ip])
                ->all(),
        ];

        $this->auditLogger->record(AuditAction::PersonalDataExported, $subject, [], $actor);

        return $data;
    }

    /**
     * @return list<array{ip: mixed, user_agent: mixed, last_activity: string|null}>
     */
    private function sessions(User $subject): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $rows = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $subject->getKey())
            ->orderBy('last_activity')
            ->get(['ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row): array => ['ip' => $row->ip_address, 'user_agent' => $row->user_agent, 'last_activity' => $this->time(Carbon::createFromTimestampUTC((int) $row->last_activity))])
            ->all();

        return array_values($rows);
    }

    private function time(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : Carbon::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
