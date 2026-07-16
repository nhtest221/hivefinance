<?php

namespace App\Identity\Application;

final readonly class ApprovalExecutionContext
{
    public function __construct(
        public string $approvalId,
        public string $entityId,
        public string $makerId,
        public string $approverId,
        public string $correlationId,
        public string $causationId,
        public string $originatingCorrelationId,
        public ?int $originalIfMatch,
    ) {}
}
