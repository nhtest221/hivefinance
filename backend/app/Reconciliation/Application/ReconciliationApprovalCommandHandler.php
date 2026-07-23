<?php

namespace App\Reconciliation\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

/**
 * API Contracts §14.2, §14.8, §14.9; M6-GOV-001 item 9: mandatory durable four-eyes for
 * CreateEntryForBankLine, CompleteReconciliation, and ReopenReconciliation — unconditional,
 * matching M4 Hard Close, not the M2-M5 policy-conditional pattern.
 */
final readonly class ReconciliationApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private ReconciliationService $reconciliations, private string $type) {}

    public function commandType(): string
    {
        return 'reconciliation_'.$this->type;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $result = $this->reconciliations->executeApproved($this->type, $payload, $context);
        if ($result->status >= 400) {
            // Regression at approved-replay time must leave the approval Pending, never
            // Approved — throwing aborts the enclosing transaction (matches
            // PeriodApprovalCommandHandler's identical rationale for Hard Close).
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'reconciliation_execution_failed'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
