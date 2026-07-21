<?php

namespace App\Settlement\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

final readonly class SettlementApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private SettlementService $settlement, private string $type) {}

    public function commandType(): string
    {
        return 'settlement_'.$this->type;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $result = $this->settlement->executeApproved($this->type, $payload, $context);
        if ($result->status >= 400) {
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'settlement_execution_failed'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
