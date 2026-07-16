<?php

namespace App\Identity\Application;

interface ApprovalPolicyQuery
{
    public function isConfigured(string $entityId): bool;
}
