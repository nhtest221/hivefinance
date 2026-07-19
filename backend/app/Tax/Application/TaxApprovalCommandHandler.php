<?php

namespace App\Tax\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;

final readonly class TaxApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private TaxCommandExecutor $executor, private string $type) {}

    public function commandType(): string
    {
        return $this->type;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $body = $this->executor->execute($this->type, $payload, $context->entityId, $context->makerId, $context->correlationId);

        return new ApprovalCommandResult($this->type === 'tax_pack_configure' && isset($payload['expected_version']) ? 200 : 201, $body);
    }
}
