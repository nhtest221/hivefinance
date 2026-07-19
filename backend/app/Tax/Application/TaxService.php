<?php

namespace App\Tax\Application;

use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Domain\OriginatingCommand;
use App\Models\Tax\TaxCode;
use App\Models\Tax\TaxCodeVersion;
use App\Models\Tax\TaxPack;
use App\Models\User;
use App\Support\Pagination\StableCursor;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class TaxService
{
    public function __construct(private TaxAuthorizationService $authorization, private ApprovalLifecycleService $approvals) {}

    /** @param array<string, mixed> $data */
    public function requestCommand(User $actor, string $entityId, string $type, array $data, ?string $key, ?string $ifMatch, string $correlationId): TaxActionResult
    {
        $permission = $type === 'tax_pack_configure' ? 'tax.packs.manage' : 'tax.codes.manage';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denied($permission);
        }
        $jurisdictions = config('valuation.tax.jurisdictions');
        if (isset($data['jurisdiction']) && (! is_array($jurisdictions) || ! in_array($data['jurisdiction'], $jurisdictions, true))) {
            return $this->error('invariant_violation', 'The tax jurisdiction is not configured.', 422);
        }
        if (! is_string($key) || ! Str::isUuid($key)) {
            return $this->error('validation', 'Idempotency-Key must be a UUID.', 400);
        }
        if ($type === 'tax_code_create' && TaxCode::query()->where('entity_id', $entityId)->where('code', $data['code'])->exists()) {
            return $this->error('duplicate_resource', 'The tax code already exists.', 409);
        }
        if ($type === 'tax_code_version_create') {
            $code = TaxCode::query()->where('entity_id', $entityId)->find($data['tax_code_id']);
            if (! $code instanceof TaxCode) {
                return $this->error('not_found', 'The tax code was not found.', 404);
            }
            if ($ifMatch !== null && preg_match('/^"?\d+"?$/', $ifMatch) === 1 && $code->version !== (int) trim($ifMatch, '"')) {
                return new TaxActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The tax code version is stale.', 'details' => [], 'required_version' => $code->version], 409);
            }
            $methods = config('valuation.tax.calculation_methods');
            $returnBoxKeys = config('valuation.tax.return_box_keys');
            if (! is_array($methods) || ! in_array($data['calculation_method'], $methods, true)
                || ! is_array($returnBoxKeys) || array_diff(array_keys($data['return_box_mapping']), $returnBoxKeys) !== []) {
                return $this->error('invariant_violation', 'The tax calculation or return-box mapping is not configured.', 422);
            }
        }
        if ($type === 'tax_pack_configure') {
            $templateKeys = config('valuation.tax.pack_template_keys');
            $policyKeys = config('valuation.tax.pack_policy_keys');
            if (! is_array($templateKeys) || array_diff(array_keys($data['return_template']), $templateKeys) !== []
                || ! is_array($policyKeys) || array_diff(array_keys($data['policy']), $policyKeys) !== []) {
                return new TaxActionResult(['error_code' => 'invariant_violation', 'message' => 'The TaxPack schema is not configured.', 'details' => ['rule' => 'invalid_tax_pack_configuration']], 422);
            }
            $pack = TaxPack::query()->where('entity_id', $entityId)->where('jurisdiction', $data['jurisdiction'])->first();
            if ($pack instanceof TaxPack && $ifMatch === null) {
                return $this->error('precondition_required', 'If-Match is required for TaxPack revision.', 428);
            }
            if (! $pack instanceof TaxPack && $ifMatch !== null) {
                return $this->error('validation', 'If-Match is omitted for TaxPack creation.', 400);
            }
            if ($pack instanceof TaxPack && preg_match('/^"?\d+"?$/', (string) $ifMatch) === 1 && $pack->version !== (int) trim((string) $ifMatch, '"')) {
                return new TaxActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The TaxPack version is stale.', 'details' => [], 'required_version' => $pack->version], 409);
            }
        }
        $resourceId = isset($data['tax_code_id']) && is_string($data['tax_code_id']) ? $data['tax_code_id'] : null;
        $expected = null;
        if ($type === 'tax_code_version_create' || ($type === 'tax_pack_configure' && $ifMatch !== null)) {
            if ($ifMatch === null || preg_match('/^"?\d+"?$/', $ifMatch) !== 1) {
                return $this->error('precondition_required', 'If-Match is required.', 428);
            }
            $expected = (int) trim($ifMatch, '"');
            $data['expected_version'] = $expected;
        }
        $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand($type, 1, $data, $resourceId, $permission, $expected), $type, $key, $correlationId);

        return new TaxActionResult($result->payload, $result->status, $result->headers);
    }

    public function list(User $actor, string $entityId, ?string $jurisdiction, ?string $status, ?string $effectiveOn, int $limit, mixed $cursor): TaxActionResult
    {
        if (! $this->authorization->can($actor, $entityId, 'tax.codes.read')) {
            return $this->authorization->denied('tax.codes.read');
        }
        $binding = ['entity_id' => $entityId, 'jurisdiction' => $jurisdiction, 'status' => $status, 'effective_on' => $effectiveOn, 'order' => 'jurisdiction,code,id'];
        try {
            [$decodedCursor, $boundary] = StableCursor::decode(is_string($cursor) ? $cursor : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->error('validation', $exception->getMessage(), 400);
        }
        $page = TaxCode::query()->where('entity_id', $entityId)->where('created_at', '<=', $boundary)->when($jurisdiction, fn ($q) => $q->where('jurisdiction', $jurisdiction))->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('jurisdiction')->orderBy('code')->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', $decodedCursor);
        $rows = $page->getCollection()->map(function (TaxCode $code) use ($effectiveOn): array {
            $row = self::presentCode($code, false);
            if ($effectiveOn !== null) {
                $row['applicable_version_id'] = $code->versions()->whereDate('effective_from', '<=', $effectiveOn)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $effectiveOn))->value('id');
            }

            return $row;
        })->all();

        return new TaxActionResult(['tax_codes' => $rows, 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    public function show(User $actor, string $entityId, string $id): TaxActionResult
    {
        if (! $this->authorization->can($actor, $entityId, 'tax.codes.read')) {
            return $this->authorization->denied('tax.codes.read');
        }
        $code = TaxCode::query()->with('versions')->where('entity_id', $entityId)->find($id);

        return $code instanceof TaxCode ? new TaxActionResult(['tax_code' => self::presentCode($code, true)]) : $this->error('not_found', 'The tax code was not found.', 404);
    }

    /** @return array<string, mixed> */
    public static function presentCode(TaxCode $code, bool $includeVersions): array
    {
        $row = ['id' => $code->id, 'code' => $code->code, 'name' => $code->name, 'jurisdiction' => $code->jurisdiction, 'status' => $code->status, 'version' => $code->version];
        if ($includeVersions) {
            $row['versions'] = $code->versions->map(fn (TaxCodeVersion $version) => self::presentVersion($version))->all();
        } elseif ($code->relationLoaded('versions')) {
            $row['versions'] = [];
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public static function presentVersion(TaxCodeVersion $version): array
    {
        return ['id' => $version->id, 'version_number' => $version->version_number, 'treatment' => $version->treatment, 'rate' => $version->rate, 'recoverable' => $version->recoverable, 'calculation_method' => $version->calculation_method, 'gl_mapping' => $version->gl_mapping, 'return_box_mapping' => $version->return_box_mapping, 'effective_from' => $version->effective_from->toDateString(), 'effective_to' => $version->effective_to?->toDateString(), 'referenced' => $version->referenced];
    }

    /** @return array<string, mixed> */
    public static function presentPack(TaxPack $pack): array
    {
        return ['id' => $pack->id, 'jurisdiction' => $pack->jurisdiction, 'name' => $pack->name, 'version' => $pack->version];
    }

    private function error(string $code, string $message, int $status): TaxActionResult
    {
        return new TaxActionResult(['error_code' => $code, 'message' => $message, 'details' => []], $status);
    }
}
