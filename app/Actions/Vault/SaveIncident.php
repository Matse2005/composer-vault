<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\Incident;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SaveIncident
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Create an incident record, or change one. The notes are free text and stay out of the audit entry.
     */
    public function handle(?Incident $incident, string $title, DateTimeInterface $detectedAt, ?DateTimeInterface $closedAt, ?string $notes, User $actor): Incident
    {
        Gate::forUser($actor)->authorize(Ability::ManageCompliance);

        return DB::transaction(function () use ($incident, $title, $detectedAt, $closedAt, $notes, $actor): Incident {
            $creating = $incident === null;
            $incident ??= new Incident;

            $incident->forceFill([
                'title' => $title,
                'detected_at' => $detectedAt,
                'closed_at' => $closedAt,
                'notes' => $notes === '' ? null : $notes,
            ]);

            if ($creating) {
                $incident->created_by = $actor->getKey();
            }

            $incident->save();

            $this->auditLogger->record($creating ? AuditAction::IncidentCreated : AuditAction::IncidentUpdated, $incident, ['closed' => $closedAt !== null], $actor);

            return $incident;
        });
    }
}
