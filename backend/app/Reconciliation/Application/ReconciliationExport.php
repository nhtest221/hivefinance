<?php

namespace App\Reconciliation\Application;

final readonly class ReconciliationExport
{
    public function __construct(public string $content, public string $mimeType, public string $filename) {}
}
