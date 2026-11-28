<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\AccessReview;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Compliance\AccessReviewReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CompleteAccessReview
{
    public function __construct(private AuditLogger $auditLogger, private AccessReviewReport $report) {}

    /**
     * Record that the access review was done: the notes and a snapshot of the counts of every section. The notes stay
     * out of the audit entry.
     */
    public function handle(?string $notes, User $actor): AccessReview
    {
        Gate::forUser($actor)->authorize(Ability::ComplianceManage);

        return DB::transaction(function () use ($notes, $actor): AccessReview {
            $review = (new AccessReview)->forceFill([
                'completed_by' => $actor->getKey(),
                'completed_at' => now(),
                'notes' => $notes === '' ? null : $notes,
                'snapshot' => $this->report->snapshot(),
            ]);
            $review->save();

            $this->auditLogger->record(AuditAction::AccessReviewCompleted, $review, [], $actor);

            return $review;
        });
    }
}
