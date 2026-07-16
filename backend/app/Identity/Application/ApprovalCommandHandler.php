<?php

namespace App\Identity\Application;

interface ApprovalCommandHandler
{
    public function commandType(): string;

    public function schemaVersion(): int;

    /**
     * Execute only transactional database, audit, and outbox work. External side effects
     * must be delivered from the transactional outbox after this transaction commits.
     *
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult;
}
