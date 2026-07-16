<?php

namespace App\Identity\Domain;

final readonly class OriginatingCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public int $schemaVersion,
        public array $payload,
        public ?string $resourceId,
        public string $requiredApprovalCapability,
        public ?int $originalIfMatch = null,
    ) {}
}
