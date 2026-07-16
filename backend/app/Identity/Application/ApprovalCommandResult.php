<?php

namespace App\Identity\Application;

final readonly class ApprovalCommandResult
{
    /** @param array<string, mixed> $body */
    public function __construct(public int $status, public array $body) {}
}
