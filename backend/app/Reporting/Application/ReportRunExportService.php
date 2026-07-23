<?php

namespace App\Reporting\Application;

use App\Models\Reporting\ReportRun;
use App\Models\User;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * API Contracts §13.13: export reads only the immutable ReportRun.content snapshot and
 * never reruns the report calculation. PDF and CSV only — XLSX is excluded and deferred.
 */
final readonly class ReportRunExportService
{
    public function __construct(private DocumentCommandSupport $commands, private ReportRunRepository $runs) {}

    public function export(User $actor, string $entityId, string $id, string $format): ReportRunExport|DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.report_runs.read')) {
            return $denied;
        }
        if (! in_array($format, ['pdf', 'csv'], true)) {
            return $this->commands->error('validation', 'format must be pdf or csv. xlsx is excluded and deferred.', 400);
        }
        $run = $this->runs->getById($entityId, $id);
        if ($run === null) {
            return $this->commands->error('not_found', 'The ReportRun was not found.', 404);
        }

        $metadata = [
            'entity_id' => $run->entity_id, 'report_type' => $run->report_type, 'filters' => $run->filters, 'basis' => $run->basis,
            'period_ref' => $run->period_ref, 'as_of' => $run->as_of?->toDateString(), 'generated_at' => $run->generated_at->toISOString(),
            'content_hash' => $run->content_hash, 'layout_version' => $run->layout_version, 'classification_version' => $run->classification_version,
            'state' => $run->state,
        ];
        $rows = $this->flatten($run->content);
        $filename = $run->report_type.'_'.($run->as_of?->toDateString() ?? $run->period_ref).'_'.$run->id;

        return $format === 'csv'
            ? new ReportRunExport($this->toCsv($metadata, $rows), 'text/csv', $filename.'.csv')
            : new ReportRunExport($this->toPdf($run, $metadata, $rows), 'application/pdf', $filename.'.pdf');
    }

    /** @param array<string, mixed> $content
     * @return list<array{path: string, value: string}>
     */
    private function flatten(array $content, string $prefix = ''): array
    {
        $rows = [];
        foreach ($content as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $rows = [...$rows, ...$this->flatten($value, $path)];
            } else {
                $rows[] = ['path' => $path, 'value' => match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    $value === null => '',
                    default => (string) $value,
                }];
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $metadata
     * @param  list<array{path: string, value: string}>  $rows
     */
    private function toCsv(array $metadata, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['field', 'value'], escape: '\\');
        foreach ($metadata as $key => $value) {
            fputcsv($stream, [$key, is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) ($value ?? '')], escape: '\\');
        }
        fputcsv($stream, [], escape: '\\');
        fputcsv($stream, ['content_path', 'value'], escape: '\\');
        foreach ($rows as $row) {
            fputcsv($stream, [$row['path'], $row['value']], escape: '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (string) $csv;
    }

    /** @param array<string, mixed> $metadata
     * @param  list<array{path: string, value: string}>  $rows
     */
    private function toPdf(ReportRun $run, array $metadata, array $rows): string
    {
        $escape = fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $metaHtml = collect($metadata)->map(fn ($v, $k) => '<tr><th>'.$escape($k).'</th><td>'.$escape(is_array($v) ? json_encode($v) : ($v ?? '')).'</td></tr>')->implode('');
        $rowsHtml = collect($rows)->map(fn ($r) => '<tr><td>'.$escape($r['path']).'</td><td>'.$escape($r['value']).'</td></tr>')->implode('');
        $html = <<<HTML
<html><head><style>
body{font-family:sans-serif;font-size:11px;} h1{font-size:16px;} table{border-collapse:collapse;width:100%;margin-bottom:16px;}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;} th{background:#f0f0f0;width:220px;}
.state{font-weight:bold;color:{$this->stateColor($run->state)};}
</style></head><body>
<h1>{$escape(ucwords(str_replace('_', ' ', $run->report_type)))}</h1>
<p class="state">State: {$escape($run->state)}</p>
<table>{$metaHtml}</table>
<table><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>{$rowsHtml}</tbody></table>
</body></html>
HTML;

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $dompdf->output();
    }

    private function stateColor(string $state): string
    {
        return match ($state) {
            'Approved' => 'green',
            'Superseded', 'Rejected' => 'red',
            default => 'black',
        };
    }
}
