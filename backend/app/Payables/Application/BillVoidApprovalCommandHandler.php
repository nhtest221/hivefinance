<?php

namespace App\Payables\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

final readonly class BillVoidApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private BillVoidService $service) {}

    public function commandType(): string
    {
        return 'bill_void';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $result = $this->service->executeApproved($payload, $context);
        if ($result->status >= 400) {
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'bill_void_execution_failed'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
