<?php

namespace App\Settlement\Infrastructure;

use App\Models\Settlement\AllocationLink;
use App\Settlement\Application\DocumentActivityQuery;

final class EloquentDocumentActivityQuery implements DocumentActivityQuery
{
    public function hasSettlementActivity(string $entityId, string $documentId): bool
    {
        return AllocationLink::query()->where('entity_id', $entityId)->where('document_id', $documentId)->exists();
    }
}
