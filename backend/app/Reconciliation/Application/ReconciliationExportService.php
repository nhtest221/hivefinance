<?php

namespace App\Reconciliation\Application;

use App\Models\Reconciliation\BankReconciliation;
use App\Models\Reconciliation\ReconciliationStatementLine;
use App\Models\User;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/**
 * API Contracts §14.12: export reads only the currently persisted state (a live preview for
 * Draft/InProgress/Reopened, the frozen snapshot for Completed) and never recomputes matching.
 * PDF and CSV only — XLSX excluded, matching M5-GOV-001's precedent.
 */
final readonly class ReconciliationExportService
{
    public function __construct(private DocumentCommandSupport $commands, private BankReconciliationRepository $reconciliations, private ReconciliationAccountRepository $accounts) {}

    public function export(User $actor, string $entityId, string $id, string $format): ReconciliationExport|DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.read')) {
            return $denied;
        }
        if (! in_array($format, ['pdf', 'csv'], true)) {
            return $this->commands->error('validation', 'format must be pdf or csv. xlsx is excluded and deferred.', 400);
        }
        $reconciliation = $this->reconciliations->getById($entityId, $id);
        if ($reconciliation === null) {
            return $this->commands->error('not_found', 'The reconciliation was not found.', 404);
        }
        $account = $this->accounts->getById($entityId, $reconciliation->reconciliation_account_id);
        $lines = $this->reconciliations->linesFor($id);
        $difference = $this->difference($reconciliation, $lines);

        $metadata = [
            'reconciliation_account_id' => $reconciliation->reconciliation_account_id, 'display_name' => $account?->display_name,
            'period_ref' => $reconciliation->period_ref, 'opening_balance' => $reconciliation->opening_balance, 'closing_balance' => $reconciliation->closing_balance,
            'unexplained_difference' => $difference, 'state' => $reconciliation->state, 'content_hash' => $reconciliation->content_hash,
        ];
        $filename = 'reconciliation_'.$reconciliation->period_ref.'_'.$reconciliation->id;

        return $format === 'csv'
            ? new ReconciliationExport($this->toCsv($metadata, $lines), 'text/csv', $filename.'.csv')
            : new ReconciliationExport($this->toPdf($reconciliation, $metadata, $lines), 'application/pdf', $filename.'.pdf');
    }

    /** @param Collection<int, ReconciliationStatementLine> $lines */
    private function difference(BankReconciliation $r, Collection $lines): string
    {
        $sum = $lines->reduce(fn (string $s, ReconciliationStatementLine $l): string => ExactDecimal::add($s, $l->amount), '0.0000');
        $expected = ExactDecimal::add($r->opening_balance, $sum);

        return ExactDecimal::subtract($r->closing_balance, $expected);
    }

    /** @param array<string, mixed> $metadata
     * @param Collection<int, ReconciliationStatementLine> $lines */
    private function toCsv(array $metadata, Collection $lines): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['field', 'value'], escape: '\\');
        foreach ($metadata as $key => $value) {
            fputcsv($stream, [$key, (string) ($value ?? '')], escape: '\\');
        }
        fputcsv($stream, [], escape: '\\');
        fputcsv($stream, ['line_id', 'date', 'narration', 'amount', 'currency', 'status'], escape: '\\');
        foreach ($lines as $line) {
            fputcsv($stream, [$line->id, $line->transaction_date->toDateString(), $line->narration, $line->amount, $line->currency, $line->status], escape: '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (string) $csv;
    }

    /** @param array<string, mixed> $metadata
     * @param Collection<int, ReconciliationStatementLine> $lines */
    private function toPdf(BankReconciliation $r, array $metadata, Collection $lines): string
    {
        $escape = fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $metaHtml = collect($metadata)->map(fn ($v, $k) => '<tr><th>'.$escape($k).'</th><td>'.$escape($v ?? '').'</td></tr>')->implode('');
        $rowsHtml = $lines->map(fn (ReconciliationStatementLine $l) => '<tr><td>'.$escape($l->transaction_date->toDateString()).'</td><td>'.$escape($l->narration).'</td><td>'.$escape($l->amount).' '.$escape($l->currency).'</td><td>'.$escape($l->status).'</td></tr>')->implode('');
        $html = <<<HTML
<html><head><style>
body{font-family:sans-serif;font-size:11px;} h1{font-size:16px;} table{border-collapse:collapse;width:100%;margin-bottom:16px;}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;} th{background:#f0f0f0;width:220px;}
.state{font-weight:bold;color:{$this->stateColor($r->state)};}
</style></head><body>
<h1>Bank Reconciliation Statement</h1>
<p class="state">State: {$escape($r->state)}</p>
<table>{$metaHtml}</table>
<table><thead><tr><th>Date</th><th>Narration</th><th>Amount</th><th>Status</th></tr></thead><tbody>{$rowsHtml}</tbody></table>
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
            'Completed' => 'green',
            default => 'black',
        };
    }
}
