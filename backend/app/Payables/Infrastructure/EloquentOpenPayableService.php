<?php

namespace App\Payables\Infrastructure;

use App\Models\Payables\Bill;
use App\Models\Payables\Vendor;
use App\Payables\Application\OpenPayableService;
use App\Support\Documents\ExactDecimal;
use InvalidArgumentException;

final class EloquentOpenPayableService implements OpenPayableService
{
    public function getVendor(string $entityId, string $vendorId): ?array
    {
        $vendor = Vendor::query()->where('entity_id', $entityId)->find($vendorId);

        return $vendor ? ['party_id' => $vendor->id, 'status' => $vendor->status, 'currency' => $vendor->default_currency] : null;
    }

    public function getOpenPayable(string $entityId, string $documentId): ?array
    {
        $bill = Bill::query()->where('entity_id', $entityId)->find($documentId);

        return $bill ? $this->present($bill) : null;
    }

    public function applySettlement(string $entityId, string $documentId, string $amount, int $expectedVersion): array
    {
        return $this->change($entityId, $documentId, $amount, $expectedVersion, false);
    }

    public function reverseSettlement(string $entityId, string $documentId, string $amount, int $expectedVersion): array
    {
        return $this->change($entityId, $documentId, $amount, $expectedVersion, true);
    }

    /** @return array<string,mixed> */
    private function change(string $entityId, string $documentId, string $amount, int $expectedVersion, bool $reverse): array
    {
        $bill = Bill::query()->where('entity_id', $entityId)->find($documentId);
        if (! $bill) {
            return ['error' => 'not_found'];
        }
        if ($bill->version !== $expectedVersion) {
            return ['error' => 'concurrency_conflict', 'required_version' => $bill->version];
        }
        if (! in_array($bill->status, ['awaiting_payment', 'partially_paid', 'paid'], true)) {
            return ['error' => 'invalid_document_state'];
        }
        try {
            $after = $reverse ? ExactDecimal::add($bill->open_balance, $amount) : ExactDecimal::subtract($bill->open_balance, $amount);
        } catch (InvalidArgumentException) {
            return ['error' => 'over_allocation'];
        }
        if ((! $reverse && str_starts_with($after, '-')) || ($reverse && ExactDecimal::compare($after, $bill->total) > 0)) {
            return ['error' => $reverse ? 'reversal_not_allowed' : 'over_allocation'];
        }
        $status = $after === '0.0000' ? 'paid' : ($after === $bill->total ? 'awaiting_payment' : 'partially_paid');
        $before = $this->present($bill);
        $updated = Bill::query()->whereKey($documentId)->where('entity_id', $entityId)->where('version', $expectedVersion)->update(['open_balance' => $after, 'status' => $status, 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return ['error' => 'concurrency_conflict', 'required_version' => (int) Bill::query()->whereKey($documentId)->value('version')];
        }
        $bill->refresh();

        return ['before' => $before, 'after' => $this->present($bill)];
    }

    /** @return array<string,mixed> */
    private function present(Bill $bill): array
    {
        return ['document_id' => $bill->id, 'document_number' => $bill->document_number, 'party_id' => $bill->vendor_id, 'currency' => $bill->currency, 'open_balance' => $bill->open_balance, 'total' => $bill->total, 'status' => $bill->status, 'version' => $bill->version, 'rate_record_id' => $bill->rate_record_id, 'exchange_rate_reference' => $bill->exchange_rate_reference];
    }
}
