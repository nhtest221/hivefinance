<?php

namespace App\Tax\Application;

use App\Ledger\Application\AccountReferenceQuery;
use App\Models\Tax\TaxCode;
use App\Models\Tax\TaxPack;
use App\Support\Audit\AuditLogger;
use App\Support\Outbox\Outbox;
use RuntimeException;

final readonly class TaxCommandExecutor
{
    public function __construct(private AuditLogger $audit, private Outbox $outbox, private AccountReferenceQuery $accounts) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(string $command, array $payload, string $entityId, string $actorId, string $correlationId): array
    {
        return match ($command) {
            'tax_code_create' => $this->createCode($payload, $entityId, $actorId, $correlationId),
            'tax_code_version_create' => $this->createVersion($payload, $entityId, $actorId, $correlationId),
            'tax_pack_configure' => $this->configurePack($payload, $entityId, $actorId, $correlationId),
            default => throw new RuntimeException('Unsupported tax approval command.'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createCode(array $data, string $entityId, string $actorId, string $correlationId): array
    {
        if (TaxCode::query()->where('entity_id', $entityId)->where('code', $data['code'])->exists()) {
            throw new RuntimeException('Duplicate tax code.');
        }
        $code = TaxCode::query()->create(['entity_id' => $entityId, 'code' => $data['code'], 'name' => $data['name'], 'jurisdiction' => $data['jurisdiction'], 'status' => 'active']);
        $presented = [...TaxService::presentCode($code, false), 'versions' => []];
        $this->audit->record('tax', 'tax_code_created', 'tax_code', $code->id, $actorId, $entityId, after: $presented, correlationId: $correlationId);

        return ['tax_code' => $presented];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createVersion(array $data, string $entityId, string $actorId, string $correlationId): array
    {
        $code = TaxCode::query()->where('entity_id', $entityId)->lockForUpdate()->findOrFail($data['tax_code_id']);
        if ($code->version !== (int) $data['expected_version']) {
            throw new RuntimeException('Stale tax code version.');
        }
        $overlap = $code->versions()->whereDate('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))->exists();
        if ($overlap) {
            throw new RuntimeException('Tax effective dates overlap.');
        }
        foreach (array_filter($data['gl_mapping']) as $accountId) {
            if (! is_string($accountId) || ! $this->accounts->isOwnedByEntity($entityId, $accountId)) {
                throw new RuntimeException('Invalid tax GL mapping.');
            }
        }
        $version = $code->versions()->create([
            'entity_id' => $entityId,
            'version_number' => ((int) $code->versions()->max('version_number')) + 1,
            'treatment' => $data['treatment'], 'rate' => $data['rate'], 'recoverable' => $data['recoverable'],
            'calculation_method' => $data['calculation_method'], 'gl_mapping' => $data['gl_mapping'], 'return_box_mapping' => $data['return_box_mapping'],
            'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
        ]);
        $code->version++;
        $code->save();
        $event = ['codeId' => $code->id, 'versionId' => $version->id, 'effectiveDates' => ['from' => $data['effective_from'], 'to' => $data['effective_to'] ?? null]];
        $this->audit->record('tax', 'tax_code_versioned', 'tax_code', $code->id, $actorId, $entityId, after: $event, correlationId: $correlationId);
        $this->outbox->record('TaxCodeVersioned', 'TaxCode', $code->id, $event, $entityId, metadata: ['correlation_id' => $correlationId]);

        return ['tax_code_version' => TaxService::presentVersion($version), 'resource_version' => $code->version];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function configurePack(array $data, string $entityId, string $actorId, string $correlationId): array
    {
        $pack = TaxPack::query()->where('entity_id', $entityId)->where('jurisdiction', $data['jurisdiction'])->lockForUpdate()->first();
        $expected = $data['expected_version'] ?? null;
        if ($pack !== null && $pack->version !== $expected) {
            throw new RuntimeException('Stale tax pack version.');
        }
        $validCodes = TaxCode::query()->where('entity_id', $entityId)->where('jurisdiction', $data['jurisdiction'])->whereIn('id', $data['tax_code_ids'])->count();
        if ($validCodes !== count(array_unique($data['tax_code_ids']))) {
            throw new RuntimeException('Invalid tax pack configuration.');
        }
        $before = $pack === null ? null : TaxService::presentPack($pack);
        $pack ??= new TaxPack(['entity_id' => $entityId, 'jurisdiction' => $data['jurisdiction']]);
        $pack->fill(['name' => $data['name'], 'tax_code_ids' => array_values(array_unique($data['tax_code_ids'])), 'return_template' => $data['return_template'], 'policy' => $data['policy']]);
        if ($pack->exists) {
            $pack->version++;
        }
        $pack->save();
        $presented = TaxService::presentPack($pack);
        $this->audit->record('tax', 'tax_pack_configured', 'tax_pack', $pack->id, $actorId, $entityId, before: $before, after: $presented, correlationId: $correlationId);
        $this->outbox->record('TaxPackConfigured', 'TaxPack', $pack->id, ['packId' => $pack->id, 'jurisdiction' => $pack->jurisdiction], $entityId, metadata: ['correlation_id' => $correlationId]);

        return ['tax_pack' => $presented];
    }
}
