<?php

namespace App\Reporting\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use RuntimeException;

final readonly class ReportRunApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private ReportRunService $reportRuns) {}

    public function commandType(): string
    {
        return 'report_run_approve';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $result = $this->reportRuns->executeApproved($payload, $context);
        if ($result->status >= 400) {
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'report_run_execution_failed'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
