<?php

namespace App\Receivables\Application;

use App\Models\Receivables\Customer;
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

final readonly class CustomerService
{
    public function __construct(private DocumentCommandSupport $commands, private AuditLogger $audit, private Outbox $outbox) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'receivables.customers.manage')) {
            return $denied;
        }
        if ($invalid = $this->commands->requireIdempotency($key)) {
            return $invalid;
        }
        if ($error = $this->validateConfiguration($data)) {
            return $error;
        }
        $operation = 'POST /v1/customers';
        $hash = $this->commands->hash($data);
        if ($replay = $this->commands->replay($actor->id, $entityId, $operation, (string) $key, $hash)) {
            return $replay;
        }
        try {
            return DB::transaction(function () use ($actor, $entityId, $data, $key, $operation, $hash): DocumentActionResult {
                $customer = Customer::query()->create([...$this->attributes($data), 'entity_id' => $entityId, 'status' => 'active', 'version' => 1, 'created_by' => $actor->id]);
                $body = ['customer' => $this->present($customer)];
                $safe = $this->safe($customer);
                $this->audit->record('receivables', 'customer_created', 'customer', $customer->id, $actor->id, $entityId, after: $safe, correlationId: $this->correlation());
                $this->outbox->record('CustomerCreated', 'Customer', $customer->id, $safe, $entityId);
                $this->commands->store($actor->id, $entityId, $operation, (string) $key, $hash, 201, $body);

                return new DocumentActionResult($body, 201);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->commands->error('duplicate_resource', 'The normalized tax identifier already exists for this jurisdiction and master type.', 409);
        }
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'receivables.customers.manage')) {
            return $denied;
        }
        if ($invalid = $this->commands->requireIdempotency($key)) {
            return $invalid;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $operation = 'PATCH /v1/customers/'.$id;
        $hash = $this->commands->hash([$data, $expected]);
        if ($replay = $this->commands->replay($actor->id, $entityId, $operation, (string) $key, $hash)) {
            return $replay;
        }
        $customer = Customer::query()->where('entity_id', $entityId)->find($id);
        if (! $customer) {
            return $this->notFound();
        }
        if ($customer->status !== 'active') {
            return $this->commands->error('invariant_violation', 'A deactivated customer cannot be updated.', 422, ['rule' => 'customer_deactivated']);
        }
        if ($customer->version !== $expected) {
            return $this->conflict($customer->version);
        }
        $merged = [...$customer->only(['name', 'type', 'jurisdiction', 'tax_identifier', 'default_currency', 'payment_terms', 'contact', 'address']), ...$data];
        if ($error = $this->validateConfiguration($merged)) {
            return $error;
        }
        try {
            return DB::transaction(function () use ($actor, $entityId, $customer, $data, $expected, $key, $operation, $hash): DocumentActionResult {
                $before = $this->safe($customer);
                $changes = $this->attributes([...$customer->toArray(), ...$data]);
                $changes['version'] = $expected + 1;
                $changes['updated_at'] = now('UTC');
                $updated = Customer::query()->whereKey($customer->id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'active')->update($changes);
                if ($updated !== 1) {
                    return $this->conflict((int) Customer::query()->whereKey($customer->id)->value('version'));
                }
                $customer->refresh();
                $body = ['customer' => $this->present($customer)];
                $this->audit->record('receivables', 'customer_updated', 'customer', $customer->id, $actor->id, $entityId, $before, $this->safe($customer), correlationId: $this->correlation());
                $this->outbox->record('CustomerUpdated', 'Customer', $customer->id, $this->safe($customer), $entityId);
                $this->commands->store($actor->id, $entityId, $operation, (string) $key, $hash, 200, $body);

                return new DocumentActionResult($body);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->commands->error('duplicate_resource', 'The normalized tax identifier already exists for this jurisdiction and master type.', 409);
        }
    }

    public function deactivate(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'receivables.customers.manage')) {
            return $denied;
        }
        if ($invalid = $this->commands->requireIdempotency($key)) {
            return $invalid;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $operation = 'POST /v1/customers/'.$id.'/deactivate';
        $hash = $this->commands->hash([$id, $expected]);
        if ($replay = $this->commands->replay($actor->id, $entityId, $operation, (string) $key, $hash)) {
            return $replay;
        }
        $customer = Customer::query()->where('entity_id', $entityId)->find($id);
        if (! $customer) {
            return $this->notFound();
        }
        if ($customer->status !== 'active') {
            return $this->commands->error('invariant_violation', 'The customer is already deactivated.', 422, ['rule' => 'customer_already_deactivated']);
        }
        if ($customer->version !== $expected) {
            return $this->conflict($customer->version);
        }

        return DB::transaction(function () use ($actor, $entityId, $customer, $expected, $key, $operation, $hash): DocumentActionResult {
            $before = $this->safe($customer);
            $updated = Customer::query()->whereKey($customer->id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'active')->update(['status' => 'deactivated', 'version' => $expected + 1, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                return $this->conflict((int) Customer::query()->whereKey($customer->id)->value('version'));
            }
            $customer->refresh();
            $body = ['customer' => $this->present($customer)];
            $this->audit->record('receivables', 'customer_deactivated', 'customer', $customer->id, $actor->id, $entityId, $before, $this->safe($customer), correlationId: $this->correlation());
            $this->outbox->record('CustomerDeactivated', 'Customer', $customer->id, $this->safe($customer), $entityId);
            $this->commands->store($actor->id, $entityId, $operation, (string) $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'receivables.customers.read')) {
            return $denied;
        }$customer = Customer::query()->where('entity_id', $entityId)->find($id);

        return $customer ? new DocumentActionResult(['customer' => $this->present($customer)]) : $this->notFound();
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'receivables.customers.read')) {
            return $denied;
        }
        $limit = (int) ($filters['limit'] ?? 50);
        $status = (string) ($filters['status'] ?? 'active');
        $binding = ['entity_id' => $entityId, 'status' => $status, 'type' => $filters['type'] ?? null, 'search' => $filters['search'] ?? null, 'order' => 'normalized_name,id'];
        try {
            [$cursor,$boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $e) {
            return $this->commands->error('validation', $e->getMessage(), 400);
        }
        $query = Customer::query()->where('entity_id', $entityId)->where('status', $status)->where('created_at', '<=', $boundary);
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }if (isset($filters['search'])) {
            $search = TaxIdentifier::normalize((string) $filters['search']);
            $query->where(fn ($q) => $q->where('normalized_name', 'like', '%'.$search.'%')->orWhere('normalized_tax_identifier', 'like', '%'.$search.'%'));
        }
        $page = $query->orderBy('normalized_name')->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return new DocumentActionResult(['customers' => $page->getCollection()->map(fn (Customer $c) => $this->present($c))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function attributes(array $data): array
    {
        return ['name' => trim((string) $data['name']), 'normalized_name' => TaxIdentifier::normalize((string) $data['name']), 'type' => $data['type'], 'jurisdiction' => isset($data['jurisdiction']) ? strtoupper((string) $data['jurisdiction']) : null, 'tax_identifier' => $data['tax_identifier'] ?? null, 'normalized_tax_identifier' => TaxIdentifier::normalize($data['tax_identifier'] ?? null), 'default_currency' => $data['default_currency'], 'payment_terms' => $data['payment_terms'], 'contact' => $data['contact'] ?? null, 'address' => $data['address'] ?? null];
    }

    /** @param array<string,mixed> $data */
    private function validateConfiguration(array $data): ?DocumentActionResult
    {
        if (! in_array($data['default_currency'], (array) config('documents.supported_currencies'), true) || ! array_key_exists((string) $data['payment_terms'], (array) config('documents.payment_terms'))) {
            return $this->commands->error('missing_customer_configuration', 'Customer currency or payment terms configuration is unavailable.', 422);
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function present(Customer $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'type' => $c->type, 'jurisdiction' => $c->jurisdiction, 'tax_identifier' => $c->tax_identifier, 'default_currency' => $c->default_currency, 'payment_terms' => $c->payment_terms, 'contact' => $c->contact, 'address' => $c->address, 'status' => $c->status, 'version' => $c->version, 'created_at' => $c->created_at?->toISOString(), 'updated_at' => $c->updated_at?->toISOString()];
    }

    /** @return array<string,mixed> */
    private function safe(Customer $c): array
    {
        return array_diff_key($this->present($c), array_flip(['contact', 'address', 'tax_identifier']));
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The customer was not found.', 404);
    }

    private function conflict(int $v): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The customer version has changed.', 'details' => [], 'required_version' => $v], 409);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
