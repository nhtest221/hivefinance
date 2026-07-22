<?php

namespace App\Settlement\Application;

/**
 * Settlement-owned read query (AP-001) letting Receivables/Payables check whether a
 * document has ever been touched by a Settlement allocation, without reading
 * settlement_allocation_links directly. Used by Invoice/Bill void's safe-window check
 * (Aggregate Design "invoice void only if all 4 safe-window conditions").
 */
interface DocumentActivityQuery
{
    public function hasSettlementActivity(string $entityId, string $documentId): bool;
}
