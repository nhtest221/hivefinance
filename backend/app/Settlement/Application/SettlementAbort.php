<?php

namespace App\Settlement\Application;

use App\Support\Documents\DocumentActionResult;
use RuntimeException;

final class SettlementAbort extends RuntimeException
{
    public function __construct(public readonly DocumentActionResult $result)
    {
        parent::__construct((string) ($result->payload['error_code'] ?? 'settlement_aborted'));
    }
}
