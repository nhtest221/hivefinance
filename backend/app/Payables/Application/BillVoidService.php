<?php

namespace App\Payables\Application;

use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\JournalReversalExecutor;
use App\Models\Payables\Bill;
use App\Models\Payables\DebitNoteApplication;
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
 * M4A — Bill void (API Contracts §12.5.2). Mirror of InvoiceVoidService: Draft bills void
 * directly; approved bills use a safe-window linked reversal or are rejected. Number,
 * source facts, TaxSnapshots, and RateRecord references are preserved either way.
 */
final readonly class BillVoidService
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
        if ($denied = $this->commands->authorize($actor, $entityId, 'payables.bills.void')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $bill = Bill::query()->where('entity_id', $entityId)->find($id);
        if ($bill === null) {
            return $this->commands->error('not_found', 'The bill was not found.', 404);
        }
        if ($bill->version !== $expected) {
            return $this->conflict($bill->version);
        }
        if ($bill->status === 'draft') {
            return $this->executeDraftVoid($entityId, $id, $expected, (string) $key, $this->commands->hash([$id, $data, $expected]), $actor->id, $actor->id, (string) $key);
        }
        $blocker = $this->safeWindowBlocker($entityId, $bill);
        if ($blocker !== null) {
            return $this->commands->error('invariant_violation', 'The bill cannot be safely voided.', 422, ['rule' => 'void_window_failed', 'failed_conditions' => $blocker]);
        }
        if ($this->approvalPolicy->isConfigured($entityId)) {
            $payload = ['resource_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key, 'data' => $data];
            $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('bill_void', 1, $payload, $id, 'payables.bills.void', $expected), 'bill_void:'.$id, (string) $key, $this->correlation() ?? (string) Str::uuid());

            return new DocumentActionResult($result->payload, $result->status, $result->headers);
        }

        return $this->executeApprovedVoid($entityId, $id, $data, $expected, (string) $key, $this->commands->hash([$id, $data, $expected]), $actor->id, $actor->id, (string) $key);
    }

    /** @param array<string,mixed> $payload */
    public function executeApproved(array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $id = $payload['resource_id'] ?? null;
        $expected = $payload['expected_version'] ?? null;
        $key = $payload['idempotency_key'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($id) || ! is_int($expected) || ! is_string($key) || ! is_array($data)) {
            return $this->commands->error('validation', 'The approved bill void payload is invalid.', 400);
        }

        return $this->executeApprovedVoid($context->entityId, $id, $data, $expected, $key, $this->commands->hash([$id, $data, $expected]), $context->makerId, $context->approverId, $context->causationId);
    }

    /** @return list<string>|null */
    private function safeWindowBlocker(string $entityId, Bill $bill): ?array
    {
        $failed = [];
        if ($bill->status === 'void') {
            $failed[] = 'already_void';
        }
        if ($bill->open_balance !== $bill->total) {
            $failed[] = 'unpaid';
        }
        $period = $this->periods->findForDate($entityId, $bill->bill_date->toDateString());
        if ($period === null || $period->state !== 'Open') {
            $failed[] = 'current_open_period';
        }
        if ($period !== null && $period->vat_lock_status === 'locked') {
            $failed[] = 'vat_unfiled_or_unlocked';
        }
        if ($this->settlementActivity->hasSettlementActivity($entityId, $bill->id) || DebitNoteApplication::query()->where('entity_id', $entityId)->where('target_document_id', $bill->id)->exists()) {
            $failed[] = 'no_downstream_activity';
        }

        return $failed === [] ? null : $failed;
    }

    private function executeDraftVoid(string $entityId, string $id, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        if ($replay = $this->commands->replay($makerId, $entityId, 'bill_void:'.$id, $key, $hash)) {
            return $replay;
        }

        return DB::transaction(function () use ($entityId, $id, $expected, $key, $hash, $executorId, $causationId): DocumentActionResult {
            $updated = Bill::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'draft')->update(['status' => 'void', 'version' => $expected + 1, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                return $this->conflict((int) Bill::query()->whereKey($id)->value('version'));
            }
            $bill = Bill::query()->whereKey($id)->firstOrFail();
            $body = ['bill' => $this->summary($bill), 'reversal' => null];
            $this->audit->record('payables', 'bill_voided', 'bill', $id, $executorId, $entityId, before: ['status' => 'draft', 'version' => $expected], after: $body['bill'], correlationId: $this->correlation());
            $this->outbox->record('BillVoided', 'Bill', $id, ['billId' => $id, 'documentNumber' => null, 'status' => 'void', 'reversal' => null], $entityId, 2, ['causation_id' => $causationId]);
            $this->commands->store($executorId, $entityId, 'bill_void:'.$id, $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $data */
    private function executeApprovedVoid(string $entityId, string $id, array $data, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        if ($replay = $this->commands->replay($makerId, $entityId, 'bill_void:'.$id, $key, $hash)) {
            return $replay;
        }

        return DB::transaction(function () use ($entityId, $id, $data, $expected, $key, $hash, $executorId, $causationId): DocumentActionResult {
            $bill = Bill::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if ($bill === null) {
                return $this->commands->error('not_found', 'The bill was not found.', 404);
            }
            if ($bill->version !== $expected) {
                return $this->conflict($bill->version);
            }
            $blocker = $this->safeWindowBlocker($entityId, $bill);
            if ($blocker !== null) {
                return $this->commands->error('invariant_violation', 'The bill cannot be safely voided.', 422, ['rule' => 'void_window_failed', 'failed_conditions' => $blocker]);
            }
            $reversal = $this->reversal->execute($entityId, (string) $bill->journal_entry_id, ['entry_date' => $data['void_date'], 'reason' => $data['narrative']], $executorId, $this->correlation() ?? $causationId, $causationId);
            $updated = Bill::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expected)->update(['status' => 'void', 'version' => $expected + 1, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                return $this->conflict((int) Bill::query()->whereKey($id)->value('version'));
            }
            $bill->refresh();
            $body = ['bill' => $this->summary($bill), 'reversal' => ['journal_entry_id' => $reversal['journal']['id'], 'source_document_id' => $id]];
            $this->audit->record('payables', 'bill_voided', 'bill', $id, $executorId, $entityId, before: ['status' => 'awaiting_payment', 'version' => $expected], after: $body['bill'], metadata: ['reason_code' => $data['reason_code']], correlationId: $this->correlation());
            $this->outbox->record('BillVoided', 'Bill', $id, ['billId' => $id, 'documentNumber' => $bill->document_number, 'status' => 'void', 'reversal' => $body['reversal']], $entityId, 2, ['causation_id' => $causationId]);
            $this->commands->store($executorId, $entityId, 'bill_void:'.$id, $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @return array<string,mixed> */
    private function summary(Bill $bill): array
    {
        return ['id' => $bill->id, 'document_number' => $bill->document_number, 'status' => $bill->status, 'version' => $bill->version];
    }

    private function conflict(int $version): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The bill version has changed.', 'details' => [], 'required_version' => $version], 409);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
