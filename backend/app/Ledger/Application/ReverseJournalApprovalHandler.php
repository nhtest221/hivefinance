<?php

namespace App\Ledger\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;

final readonly class ReverseJournalApprovalHandler implements ApprovalCommandHandler
{
    public function __construct(private JournalReversalExecutor $executor) {}

    public function commandType(): string
    {
        return 'reverse_journal';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        return new ApprovalCommandResult(201, $this->executor->execute($context->entityId, (string) $payload['journal_id'], $payload['data'], $context->makerId, $context->correlationId, $context->causationId));
    }
}
