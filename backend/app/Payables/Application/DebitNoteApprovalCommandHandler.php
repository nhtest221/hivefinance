<?php

namespace App\Payables\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

final readonly class DebitNoteApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private DebitNoteService $notes, private string $type) {}

    public function commandType(): string
    {
        return 'debit_note_'.$this->type;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $result = $this->notes->executeApproved($this->type, $payload, $context);
        if ($result->status >= 400) {
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'debit_note_execution_failed'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
