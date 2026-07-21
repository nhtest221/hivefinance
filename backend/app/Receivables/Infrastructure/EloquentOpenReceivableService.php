<?php

namespace App\Receivables\Infrastructure;

use App\Models\Receivables\Customer;
use App\Models\Receivables\Invoice;
use App\Receivables\Application\OpenReceivableService;
use App\Support\Documents\ExactDecimal;
use InvalidArgumentException;

final class EloquentOpenReceivableService implements OpenReceivableService
{
    public function getCustomer(string $entityId, string $customerId): ?array
    {
        $customer = Customer::query()->where('entity_id', $entityId)->find($customerId);

        return $customer ? ['party_id' => $customer->id, 'status' => $customer->status, 'currency' => $customer->default_currency] : null;
    }

    public function getOpenReceivable(string $entityId, string $documentId): ?array
    {
        $invoice = Invoice::query()->where('entity_id', $entityId)->find($documentId);

        return $invoice ? $this->present($invoice) : null;
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
        $invoice = Invoice::query()->where('entity_id', $entityId)->find($documentId);
        if (! $invoice) {
            return ['error' => 'not_found'];
        }
        if ($invoice->version !== $expectedVersion) {
            return ['error' => 'concurrency_conflict', 'required_version' => $invoice->version];
        }
        if (! in_array($invoice->status, ['sent', 'partially_paid', 'paid'], true)) {
            return ['error' => 'invalid_document_state'];
        }
        try {
            $after = $reverse ? ExactDecimal::add($invoice->open_balance, $amount) : ExactDecimal::subtract($invoice->open_balance, $amount);
        } catch (InvalidArgumentException) {
            return ['error' => 'over_allocation'];
        }
        if ((! $reverse && str_starts_with($after, '-')) || ($reverse && ExactDecimal::compare($after, $invoice->total) > 0)) {
            return ['error' => $reverse ? 'reversal_not_allowed' : 'over_allocation'];
        }
        $status = $after === '0.0000' ? 'paid' : ($after === $invoice->total ? 'sent' : 'partially_paid');
        $before = $this->present($invoice);
        $updated = Invoice::query()->whereKey($documentId)->where('entity_id', $entityId)->where('version', $expectedVersion)->update(['open_balance' => $after, 'status' => $status, 'version' => $expectedVersion + 1, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            return ['error' => 'concurrency_conflict', 'required_version' => (int) Invoice::query()->whereKey($documentId)->value('version')];
        }
        $invoice->refresh();

        return ['before' => $before, 'after' => $this->present($invoice)];
    }

    /** @return array<string,mixed> */
    private function present(Invoice $invoice): array
    {
        return ['document_id' => $invoice->id, 'document_number' => $invoice->document_number, 'party_id' => $invoice->customer_id, 'currency' => $invoice->currency, 'open_balance' => $invoice->open_balance, 'total' => $invoice->total, 'status' => $invoice->status, 'version' => $invoice->version, 'rate_record_id' => $invoice->rate_record_id, 'exchange_rate_reference' => $invoice->exchange_rate_reference];
    }
}
