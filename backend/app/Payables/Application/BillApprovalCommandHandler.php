<?php

namespace App\Payables\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

final readonly class BillApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private BillService $bills) {}

    public function commandType(): string
    {
        return 'bill_approve';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        if (! isset($payload['bill_id'],$payload['expected_version'],$payload['idempotency_key'])) {
            throw new RuntimeException('Invalid bill approval payload.');
        }
        $result = $this->bills->executeApproval($context->entityId, (string) $payload['bill_id'], (int) $payload['expected_version'], $context->makerId, $context->approverId, (string) $payload['idempotency_key']);
        if ($result->status >= 400) {
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'bill_approval_failed'));
        }

return new ApprovalCommandResult(201, $result->payload);
    }
}
