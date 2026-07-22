<?php

namespace App\Period\Application;

final class CloseGateProviderRegistry
{
    /** @var array<string, CloseGateProvider> */
    private array $providers = [];

    public function register(string $sourceContext, CloseGateProvider $provider): void
    {
        $this->providers[$sourceContext] = $provider;
    }

    public function resolve(string $sourceContext): ?CloseGateProvider
    {
        return $this->providers[$sourceContext] ?? null;
    }
}
