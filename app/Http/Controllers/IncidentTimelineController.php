<?php

namespace App\Http\Controllers;

use App\Actions\Vault\ExportIncidentTimeline;
use App\Models\Incident;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentTimelineController extends Controller
{
    private const COLUMNS = ['type', 'time', 'event', 'sequence', 'actor_id', 'subject_type', 'subject_id', 'ip', 'result', 'token_prefix', 'project_slug'];

    /**
     * Download the timeline of an incident as JSON or CSV.
     */
    public function __invoke(Request $request, Incident $incident, string $format, ExportIncidentTimeline $export): Response
    {
        abort_unless(in_array($format, ['json', 'csv'], true), 404);

        $rows = $export->handle($incident, $request->user() ?? abort(401));
        $name = 'incident-'.$incident->getKey().'-timeline.'.$format;

        if ($format === 'json') {
            return response()->json($rows, 200, ['Content-Disposition' => 'attachment; filename="'.$name.'"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'w') ?: throw new RuntimeException('The output stream could not be opened.');
            fputcsv($handle, self::COLUMNS, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (string $column): string => (string) ($row[$column] ?? ''), self::COLUMNS), ',', '"', '');
            }

            fclose($handle);
        }, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }
}
