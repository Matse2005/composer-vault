<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\AccessLog;
use App\Models\AuditEntry;
use App\Models\Incident;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Compliance\SecurityEventStream;
use Illuminate\Support\Facades\Gate;

class ExportIncidentTimeline
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Collect the audit entries and access log rows between the detection and the close of an incident (or now), with
     * the same fields as the security event stream and nothing else, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(Incident $incident, User $actor): array
    {
        Gate::forUser($actor)->authorize(Ability::IncidentsManage);

        $from = $incident->detected_at;
        $to = $incident->closed_at ?? now();

        $audit = AuditEntry::query()->whereBetween('created_at', [$from, $to])->orderBy('sequence')->get()
            ->map(fn (AuditEntry $entry): array => SecurityEventStream::auditFields($entry));

        $access = AccessLog::query()->with(['accessToken:id,token_prefix', 'project:id,slug'])->whereBetween('created_at', [$from, $to])->orderBy('id')->get()
            ->map(fn (AccessLog $log): array => SecurityEventStream::accessFields($log, $log->accessToken?->token_prefix, $log->project?->slug));

        $rows = array_values($audit->concat($access)->sortBy('time')->all());

        $this->auditLogger->record(AuditAction::IncidentTimelineExported, $incident, ['rows' => count($rows)], $actor);

        return $rows;
    }
}
