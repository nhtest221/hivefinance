<?php

namespace App\Receivables\Application;

use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\JournalReversalExecutor;
use App\Models\Receivables\CreditNoteApplication;
use App\Models\Receivables\Invoice;
use App\Models\User;
use App\Period\Application\PeriodQuery;
use App\Settlement\Application\DocumentActivityQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M4A — Invoice void (API Contracts §12.5.1). Draft invoices void directly (no reversal,
 * no number consumed). Issued invoices use a safe-window linked reversal (Aggregate Design
 * "invoice void only if all 4 safe-window conditions": unpaid, current Open period, VAT
 * unfiled/unlocked, no downstream allocation/note application/settlement) or are otherwise
 * rejected — the number is preserved and never reused either way.
 */
final readonly class InvoiceVoidService
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private PeriodQuery $periods,
        private DocumentActivityQuery $settlementActivity,
        private JournalReversalExecutor $reversal,
        private ApprovalPolicyQuery $approvalPolicy,
        private ApprovalLifecycleService $approvals,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    /** @param array<string,mixed> $data */
    public function void(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'receivables.invoices.void')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $invoice = Invoice::query()->where('entity_id', $entityId)->find($id);
        if ($invoice === null) {
            return $this->commands->error('not_found', 'The invoice was not found.', 404);
        }
        if ($invoice->version !== $expected) {
            return $this->conflict($invoice->version);
        }
        if ($invoice->status === 'draft') {
            return $this->executeDraftVoid($entityId, $id, $expected, (string) $key, $this->commands->hash([$id, $data, $expected]), $actor->id, $actor->id, (string) $key, $data);
        }
        $blocker = $this->safeWindowBlocker($entityId, $invoice);
        if ($blocker !== null) {
            return $this->commands->error('invariant_violation', 'The invoice cannot be safely voided.', 422, ['rule' => 'void_window_failed', 'failed_conditions' => $blocker]);
        }
        if ($this->approvalPolicy->isConfigured($entityId)) {
            $payload = ['resource_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key, 'data' => $data];
            $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('invoice_void', 1, $payload, $id, 'receivables.invoices.void', $expected), 'invoice_void:'.$id, (string) $key, $this->correlation() ?? (string) Str::uuid());

            return new DocumentActionResult($result->payload, $result->status, $result->headers);
        }

        return $this->executeIssuedVoid($entityId, $id, $data, $expected, (string) $key, $this->commands->hash([$id, $data, $expected]), $actor->id, $actor->id, (string) $key);
    }

    /** @param array<string,mixed> $payload */
    public function executeApproved(array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $id = $payload['resource_id'] ?? null;
        $expected = $payload['expected_version'] ?? null;
        $key = $payload['idempotency_key'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($id) || ! is_int($expected) || ! is_string($key) || ! is_array($data)) {
            return $this->commands->error('validation', 'The approved invoice void payload is invalid.', 400);
        }

        return $this->executeIssuedVoid($context->entityId, $id, $data, $expected, $key, $this->commands->hash([$id, $data, $expected]), $context->makerId, $context->approverId, $context->causationId);
    }

    /** @return list<string>|null */
    private function safeWindowBlocker(string $entityId, Invoice $invoice): ?array
    {
        $failed = [];
        if ($invoice->status === 'void') {
            $failed[] = 'already_void';
        }
        if ($invoice->open_balance !== $invoice->total) {
            $failed[] = 'unpaid';
        }
        $period = $this->periods->findForDate($entityId, $invoice->invoice_date->toDateString());
        if ($period === null || $period->state !== 'Open') {
            $failed[] = 'current_open_period';
        }
        if ($period !== null && $period->vat_lock_status === 'locked') {
            $failed[] = 'vat_unfiled_or_unlocked';
        }
        if ($this->settlementActivity->hasSettlementActivity($entityId, $invoice->id) || CreditNoteApplication::query()->where('entity_id', $entityId)->where('target_document_id', $invoice->id)->exists()) {
            $failed[] = 'no_downstream_activity';
        }

        return $failed === [] ? null : $failed;
    }

    /** @param array<string,mixed> $data */
    private function executeDraftVoid(string $entityId, string $id, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId, array $data): DocumentActionResult
    {
        if ($replay = $this->commands->replay($makerId, $entityId, 'invoice_void:'.$id, $key, $hash)) {
            return $replay;
        }

        return DB::transaction(function () use ($entityId, $id, $expected, $key, $hash, $executorId, $causationId): DocumentActionResult {
            $updated = Invoice::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'draft')->update(['status' => 'void', 'version' => $expected + 1, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                return $this->conflict((int) Invoice::query()->whereKey($id)->value('version'));
            }
            $invoice = Invoice::query()->whereKey($id)->firstOrFail();
            $body = ['invoice' => $this->summary($invoice), 'reversal' => null];
            $this->audit->record('receivables', 'invoice_voided', 'invoice', $id, $executorId, $entityId, before: ['status' => 'draft', 'version' => $expected], after: $body['invoice'], correlationId: $this->correlation());
            $this->outbox->record('InvoiceVoided', 'Invoice', $id, ['invoiceId' => $id, 'documentNumber' => null, 'status' => 'void', 'reversal' => null], $entityId, 2, ['causation_id' => $causationId]);
            $this->commands->store($executorId, $entityId, 'invoice_void:'.$id, $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $data */
    private function executeIssuedVoid(string $entityId, string $id, array $data, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        if ($replay = $this->commands->replay($makerId, $entityId, 'invoice_void:'.$id, $key, $hash)) {
            return $replay;
        }

        return DB::transaction(function () use ($entityId, $id, $data, $expected, $key, $hash, $executorId, $causationId): DocumentActionResult {
            $invoice = Invoice::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if ($invoice === null) {
                return $this->commands->error('not_found', 'The invoice was not found.', 404);
            }
            if ($invoice->version !== $expected) {
                return $this->conflict($invoice->version);
            }
            $blocker = $this->safeWindowBlocker($entityId, $invoice);
            if ($blocker !== null) {
                return $this->commands->error('invariant_violation', 'The invoice cannot be safely voided.', 422, ['rule' => 'void_window_failed', 'failed_conditions' => $blocker]);
            }
            $reversal = $this->reversal->execute($entityId, (string) $invoice->journal_entry_id, ['entry_date' => $data['void_date'], 'reason' => $data['narrative']], $executorId, $this->correlation() ?? $causationId, $causationId);
            $updated = Invoice::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expected)->update(['status' => 'void', 'version' => $expected + 1, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                return $this->conflict((int) Invoice::query()->whereKey($id)->value('version'));
            }
            $invoice->refresh();
            $body = ['invoice' => $this->summary($invoice), 'reversal' => ['journal_entry_id' => $reversal['journal']['id'], 'source_document_id' => $id]];
            $this->audit->record('receivables', 'invoice_voided', 'invoice', $id, $executorId, $entityId, before: ['status' => 'sent', 'version' => $expected], after: $body['invoice'], metadata: ['reason_code' => $data['reason_code']], correlationId: $this->correlation());
            $this->outbox->record('InvoiceVoided', 'Invoice', $id, ['invoiceId' => $id, 'documentNumber' => $invoice->document_number, 'status' => 'void', 'reversal' => $body['reversal']], $entityId, 2, ['causation_id' => $causationId]);
            $this->commands->store($executorId, $entityId, 'invoice_void:'.$id, $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @return array<string,mixed> */
    private function summary(Invoice $invoice): array
    {
        return ['id' => $invoice->id, 'document_number' => $invoice->document_number, 'status' => $invoice->status, 'version' => $invoice->version];
    }

    private function conflict(int $version): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The invoice version has changed.', 'details' => [], 'required_version' => $version], 409);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
