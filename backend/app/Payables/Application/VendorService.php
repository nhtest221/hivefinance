<?php

namespace App\Payables\Application;

use App\Models\Payables\Vendor;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\TaxIdentifier;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class VendorService
{
    public function __construct(private DocumentCommandSupport $commands, private AuditLogger $audit, private Outbox $outbox) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.vendors.manage')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }if ($e = $this->config($data)) {
            return $e;
        }
        $op = 'POST /v1/vendors';
        $hash = $this->commands->hash($data);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }
        try {
            return DB::transaction(function () use ($actor, $entityId, $data, $key, $op, $hash): DocumentActionResult {
                $vendor = Vendor::query()->create([...$this->attributes($data), 'entity_id' => $entityId, 'status' => 'active', 'version' => 1, 'created_by' => $actor->id]);
                $body = ['vendor' => $this->present($vendor)];
                $safe = $this->safe($vendor);
                $this->audit->record('payables', 'vendor_created', 'vendor', $vendor->id, $actor->id, $entityId, after: $safe, correlationId: $this->correlation());
                $this->outbox->record('VendorCreated', 'Vendor', $vendor->id, $safe, $entityId);
                $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

                return new DocumentActionResult($body, 201);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate();
        }
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.vendors.manage')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $op = 'PATCH /v1/vendors/'.$id;
        $hash = $this->commands->hash([$data, $expected]);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }$vendor = Vendor::query()->where('entity_id', $entityId)->find($id);
        if (! $vendor) {
            return $this->notFound();
        }if ($vendor->status !== 'active') {
            return $this->commands->error('invariant_violation', 'A deactivated vendor cannot be updated.', 422, ['rule' => 'vendor_deactivated']);
        }if ($vendor->version !== $expected) {
            return $this->conflict($vendor->version);
        }
        $merged = [...$vendor->only(['name', 'jurisdiction', 'tax_identifier', 'default_currency', 'payment_terms', 'contact', 'address', 'bank_details']), ...$data];
        if ($e = $this->config($merged)) {
            return $e;
        }
        try {
            return DB::transaction(function () use ($actor, $entityId, $vendor, $merged, $expected, $key, $op, $hash): DocumentActionResult {
                $before = $this->safe($vendor);
                $changes = $this->attributes($merged);
                $changes['version'] = $expected + 1;
                $changes['updated_at'] = now('UTC');
                if (Vendor::query()->whereKey($vendor->id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'active')->update($changes) !== 1) {
                    return $this->conflict((int) Vendor::query()->whereKey($vendor->id)->value('version'));
                }$vendor->refresh();
                $body = ['vendor' => $this->present($vendor)];
                $this->audit->record('payables', 'vendor_updated', 'vendor', $vendor->id, $actor->id, $entityId, $before, $this->safe($vendor), metadata: ['bank_details_changed' => array_key_exists('bank_details', $merged)], correlationId: $this->correlation());
                $this->outbox->record('VendorUpdated', 'Vendor', $vendor->id, $this->safe($vendor), $entityId);
                $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 200, $body);

                return new DocumentActionResult($body);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate();
        }
    }

    public function deactivate(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.vendors.manage')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }$op = 'POST /v1/vendors/'.$id.'/deactivate';
        $hash = $this->commands->hash([$id, $expected]);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }$vendor = Vendor::query()->where('entity_id', $entityId)->find($id);
        if (! $vendor) {
            return $this->notFound();
        }if ($vendor->status !== 'active') {
            return $this->commands->error('invariant_violation', 'The vendor is already deactivated.', 422, ['rule' => 'vendor_already_deactivated']);
        }if ($vendor->version !== $expected) {
            return $this->conflict($vendor->version);
        }

        return DB::transaction(function () use ($actor, $entityId, $vendor, $expected, $key, $op, $hash): DocumentActionResult {
            $before = $this->safe($vendor);
            if (Vendor::query()->whereKey($vendor->id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'active')->update(['status' => 'deactivated', 'version' => $expected + 1, 'updated_at' => now('UTC')]) !== 1) {
                return $this->conflict((int) Vendor::query()->whereKey($vendor->id)->value('version'));
            }$vendor->refresh();
            $body = ['vendor' => $this->present($vendor)];
            $this->audit->record('payables', 'vendor_deactivated', 'vendor', $vendor->id, $actor->id, $entityId, $before, $this->safe($vendor), correlationId: $this->correlation());
            $this->outbox->record('VendorDeactivated', 'Vendor', $vendor->id, $this->safe($vendor), $entityId);
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.vendors.read')) {
            return $d;
        }$vendor = Vendor::query()->where('entity_id', $entityId)->find($id);

        return $vendor ? new DocumentActionResult(['vendor' => $this->present($vendor)]) : $this->notFound();
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.vendors.read')) {
            return $d;
        }$limit = (int) ($filters['limit'] ?? 50);
        $status = (string) ($filters['status'] ?? 'active');
        $binding = ['entity_id' => $entityId, 'status' => $status, 'search' => $filters['search'] ?? null, 'order' => 'normalized_name,id'];
        try {
            [$cursor,$boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $e) {
            return $this->commands->error('validation', $e->getMessage(), 400);
        }$query = Vendor::query()->where('entity_id', $entityId)->where('status', $status)->where('created_at', '<=', $boundary);
        if (isset($filters['search'])) {
            $search = TaxIdentifier::normalize((string) $filters['search']);
            $query->where(fn ($q) => $q->where('normalized_name', 'like', '%'.$search.'%')->orWhere('normalized_tax_identifier', 'like', '%'.$search.'%'));
        }$page = $query->orderBy('normalized_name')->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return new DocumentActionResult(['vendors' => $page->getCollection()->map(fn (Vendor $v) => $this->present($v))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function attributes(array $data): array
    {
        return ['name' => trim((string) $data['name']), 'normalized_name' => TaxIdentifier::normalize((string) $data['name']), 'jurisdiction' => isset($data['jurisdiction']) ? strtoupper((string) $data['jurisdiction']) : null, 'tax_identifier' => $data['tax_identifier'] ?? null, 'normalized_tax_identifier' => TaxIdentifier::normalize($data['tax_identifier'] ?? null), 'default_currency' => $data['default_currency'], 'payment_terms' => $data['payment_terms'], 'contact' => $data['contact'] ?? null, 'address' => $data['address'] ?? null, 'bank_details' => $data['bank_details'] ?? null];
    }

    /** @param array<string,mixed> $data */
    private function config(array $data): ?DocumentActionResult
    {
        if (! in_array($data['default_currency'], (array) config('documents.supported_currencies'), true) || ! array_key_exists((string) $data['payment_terms'], (array) config('documents.payment_terms'))) {
            return $this->commands->error('missing_vendor_configuration', 'Vendor currency or payment terms configuration is unavailable.', 422);
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function present(Vendor $v): array
    {
        return ['id' => $v->id, 'name' => $v->name, 'jurisdiction' => $v->jurisdiction, 'tax_identifier' => $v->tax_identifier, 'default_currency' => $v->default_currency, 'payment_terms' => $v->payment_terms, 'contact' => $v->contact, 'address' => $v->address, 'bank_details' => $this->masked($v->bank_details), 'status' => $v->status, 'version' => $v->version, 'created_at' => $v->created_at?->toISOString(), 'updated_at' => $v->updated_at?->toISOString()];
    }

    /** @param array<string,mixed>|null $bank
     * @return array<string,mixed>|null
     */
    private function masked(?array $bank): ?array
    {
        if ($bank === null) {
            return null;
        }

        return ['account_name' => $bank['account_name'] ?? null, 'institution_name' => $bank['institution_name'] ?? null, 'account_identifier_masked' => $this->mask($bank['account_identifier'] ?? null), 'routing_identifier_masked' => $this->mask($bank['routing_identifier'] ?? null)];
    }

    private function mask(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return '****'.mb_substr($value, -4);
    }

    /** @return array<string,mixed> */
    private function safe(Vendor $v): array
    {
        return array_diff_key($this->present($v), array_flip(['contact', 'address', 'tax_identifier', 'bank_details']));
    }

    private function duplicate(): DocumentActionResult
    {
        return $this->commands->error('duplicate_resource', 'The normalized tax identifier already exists for this jurisdiction and master type.', 409);
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The vendor was not found.', 404);
    }

    private function conflict(int $v): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The vendor version has changed.', 'details' => [], 'required_version' => $v], 409);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
