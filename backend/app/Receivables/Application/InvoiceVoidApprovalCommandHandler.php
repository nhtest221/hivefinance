<?php

namespace App\Receivables\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

final readonly class InvoiceVoidApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private InvoiceVoidService $service) {}

    public function commandType(): string
    {
        return 'invoice_void';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $result = $this->service->executeApproved($payload, $context);
        if ($result->status >= 400) {
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'invoice_void_execution_failed'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
