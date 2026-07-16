<?php

namespace App\Identity\Application;

final class ApprovalCommandRegistry
{
    /** @var array<string, ApprovalCommandHandler> */
    private array $handlers = [];

    public function register(ApprovalCommandHandler $handler): void
    {
        $key = $this->key($handler->commandType(), $handler->schemaVersion());
        if (array_key_exists($key, $this->handlers)) {
            throw new \LogicException("An approval handler is already registered for {$key}.");
        }
        $this->handlers[$key] = $handler;
    }

    public function resolve(string $commandType, int $schemaVersion): ?ApprovalCommandHandler
    {
        return $this->handlers[$this->key($commandType, $schemaVersion)] ?? null;
    }

    private function key(string $commandType, int $schemaVersion): string
    {
        return $commandType.':'.$schemaVersion;
    }
}
