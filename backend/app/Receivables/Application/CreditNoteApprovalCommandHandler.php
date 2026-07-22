<?php

namespace App\Receivables\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

final readonly class CreditNoteApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private CreditNoteService $notes, private string $type) {}

    public function commandType(): string
    {
        return 'credit_note_'.$this->type;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $result = $this->notes->executeApproved($this->type, $payload, $context);
        if ($result->status >= 400) {
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'credit_note_execution_failed'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
