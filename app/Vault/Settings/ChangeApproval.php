<?php

namespace App\Vault\Settings;

use App\Enums\AdminRole;
use App\Enums\AlertRule;
use App\Enums\AuditAction;
use App\Exceptions\Vault\ApprovalRefusedException;
use App\Models\PendingChange;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Compliance\SecurityAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Optional four-eyes approval of critical changes. When an Owner turned it on and more than one active Owner exists,
 * a critical change is stored as a pending change instead of applied, and another Owner approves or rejects it within
 * 24 hours. The proposer can never approve their own change. With one active Owner, changes apply directly. The
 * console recovery command never goes through approval.
 */
class ChangeApproval
{
    private const LIFETIME_HOURS = 24;

    /** The change being applied right now after an approval, so the actions do not ask for approval again. */
    private ?PendingChange $applying = null;

    private ?User $approver = null;

    public function __construct(private VaultSettings $settings, private AuditLogger $auditLogger) {}

    /**
     * Determine whether a critical change by this actor has to wait for a second Owner.
     */
    public function isRequired(): bool
    {
        if ($this->applying !== null || ! (bool) $this->settings->get('approval.enabled')) {
            return false;
        }

        return User::query()->active()->where('role', AdminRole::Owner->value)->count() > 1;
    }

    /**
     * Determine whether approval is on but cannot be used because only one Owner is active.
     */
    public function isOnWithOneOwner(): bool
    {
        return (bool) $this->settings->get('approval.enabled') && ! $this->isRequired();
    }

    /**
     * Store a change for approval and tell the other Owners.
     *
     * @param  array<string, mixed>  $payload
     */
    public function propose(string $kind, string $subject, array $payload, User $proposer): PendingChange
    {
        $change = DB::transaction(function () use ($kind, $subject, $payload, $proposer): PendingChange {
            $change = (new PendingChange)->forceFill([
                'kind' => $kind,
                'subject' => $subject,
                'payload' => $payload,
                'proposed_by' => $proposer->getKey(),
                'status' => 'pending',
                'expires_at' => now()->addHours(self::LIFETIME_HOURS),
            ]);
            $change->save();

            $this->auditLogger->record(AuditAction::ChangeProposed, null, ['kind' => $kind, 'subject' => $kind === 'owner' ? 'admin' : $subject], $proposer);

            return $change;
        });

        $alerts = app(SecurityAlerts::class);
        $alerts->send(AlertRule::ApprovalRequested, $change->id, "A change of kind {$kind} waits for approval (subject {$change->subject}, proposed by admin #{$proposer->getKey()}).", $alerts->otherOwners($proposer));

        return $change;
    }

    /**
     * Approve a pending change and apply it through the normal path, with both ids in the audit entries.
     *
     * @throws ApprovalRefusedException
     */
    public function approve(PendingChange $change, User $approver, callable $apply): void
    {
        Gate::forUser($approver)->authorize(Ability::ApprovalManage);

        DB::transaction(function () use ($change, $approver, $apply): void {
            $change = PendingChange::query()->whereKey($change->getKey())->lockForUpdate()->firstOrFail();

            if ($change->status !== 'pending') {
                throw ApprovalRefusedException::alreadyDecided();
            }

            if ($change->expires_at->isPast()) {
                throw ApprovalRefusedException::expired();
            }

            if ($change->proposed_by === $approver->getKey()) {
                throw ApprovalRefusedException::ownChange();
            }

            $this->applying = $change;
            $this->approver = $approver;

            try {
                $apply($change);
            } finally {
                $this->applying = null;
                $this->approver = null;
            }

            $change->forceFill(['status' => 'approved', 'decided_by' => $approver->getKey(), 'decided_at' => now()])->save();

            $this->auditLogger->record(AuditAction::ChangeApproved, null, ['kind' => $change->kind, 'proposed_by' => $change->proposed_by], $approver);
        });
    }

    /**
     * Reject a pending change. The proposer may withdraw their own.
     *
     * @throws ApprovalRefusedException
     */
    public function reject(PendingChange $change, User $decider): void
    {
        Gate::forUser($decider)->authorize(Ability::ApprovalManage);

        DB::transaction(function () use ($change, $decider): void {
            $change = PendingChange::query()->whereKey($change->getKey())->lockForUpdate()->firstOrFail();

            if ($change->status !== 'pending') {
                throw ApprovalRefusedException::alreadyDecided();
            }

            $change->forceFill(['status' => 'rejected', 'decided_by' => $decider->getKey(), 'decided_at' => now()])->save();

            $this->auditLogger->record(AuditAction::ChangeRejected, null, ['kind' => $change->kind, 'proposed_by' => $change->proposed_by], $decider);
        });
    }

    /**
     * The approval that is being applied right now, for the audit entries it causes.
     *
     * @return array<string, int|null>
     */
    public function auditContext(): array
    {
        return $this->applying === null ? [] : ['proposed_by' => $this->applying->proposed_by, 'approved_by' => $this->approver?->getKey()];
    }
}
