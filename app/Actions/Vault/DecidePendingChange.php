<?php

namespace App\Actions\Vault;

use App\Exceptions\Vault\ApprovalRefusedException;
use App\Models\PendingChange;
use App\Models\Role;
use App\Models\User;
use App\Vault\Settings\ChangeApproval;
use App\Vault\Settings\VaultSettings;

class DecidePendingChange
{
    public function __construct(private ChangeApproval $approval, private VaultSettings $settings) {}

    /**
     * Approve a pending change and apply it as the approver, through the normal path. The proposer cannot approve
     * their own change, and a change older than 24 hours is expired.
     *
     * @throws ApprovalRefusedException
     */
    public function approve(PendingChange $change, User $approver): void
    {
        $this->approval->approve($change, $approver, function (PendingChange $change) use ($approver): void {
            match ($change->kind) {
                'setting' => $this->settings->applyApproved($change->subject, $change->payload['value'] ?? null, $approver),
                'secret' => $this->settings->applyApprovedSecret($change->subject, $change->payload, $approver),
                'role' => app(SaveRole::class)->handle(
                    Role::query()->findOrFail($change->subject),
                    (string) $change->payload['name'],
                    array_values((array) $change->payload['permissions']),
                    array_values((array) $change->payload['sso_groups']),
                    $approver,
                ),
                'owner' => app(ChangeAdminRole::class)->handle(
                    User::query()->findOrFail((int) $change->subject),
                    Role::query()->where('slug', (string) $change->payload['role'])->firstOrFail(),
                    $approver,
                ),
                default => throw ApprovalRefusedException::alreadyDecided(),
            };
        });
    }

    /**
     * Reject a pending change, or withdraw your own.
     *
     * @throws ApprovalRefusedException
     */
    public function reject(PendingChange $change, User $decider): void
    {
        $this->approval->reject($change, $decider);
    }
}
