<?php

namespace App\CurrencyFx\Application;

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use App\Models\User;
use RuntimeException;

final readonly class FxApprovalCommandHandler implements ApprovalCommandHandler
{
    public function __construct(private string $type) {}

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
        $maker = User::query()->findOrFail($context->makerId);
        $service = app(FxService::class);
        $result = $this->type === 'fx_rate_create'
            ? $service->addRate($maker, $context->entityId, $payload['data'], (string) $payload['idempotency_key'], true)
            : $service->revalue($maker, $context->entityId, $payload['data'], (string) $payload['idempotency_key'], true);
        if ($result->status >= 400) {
            throw new RuntimeException((string) ($result->payload['error_code'] ?? 'originating_command_invalid'));
        }

        return new ApprovalCommandResult($result->status, $result->payload);
    }
}
