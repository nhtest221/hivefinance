<?php

namespace App\Period\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

final readonly class PeriodApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private PeriodCloseService $periods, private string $type) {}

    public function commandType(): string
    {
        return 'period_'.$this->type;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $result = $this->periods->executeApproved($this->type, $payload, $context);
        if ($result->status >= 400) {
            // Regression at approved-replay time (e.g. a gate is unmet again, or the
            // period changed) must leave the approval Pending, never Approved — throwing
            // here aborts the enclosing transaction so ApprovalLifecycleService records
            // only the frozen safe failed-attempt audit (API Contracts §12.6.4).
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'period_execution_failed'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
