<?php

namespace App\Tax\Application;

use App\Models\Tax\TaxCode;
use App\Models\Tax\TaxCodeVersion;
use App\Models\Tax\TaxPack;
use App\Tax\Domain\TaxSnapshot;

final class ApplicableTaxQuery
{
    public function determine(string $entityId, string $taxCodeId, string $jurisdiction, string $taxPointDate): ?TaxSnapshot
    {
        $pack = TaxPack::query()->where('entity_id', $entityId)->where('jurisdiction', $jurisdiction)->first();
        if (! $pack instanceof TaxPack || ! in_array($taxCodeId, $pack->tax_code_ids, true)) {
            return null;
        }
        $code = TaxCode::query()->where('entity_id', $entityId)->where('jurisdiction', $jurisdiction)->where('status', 'active')->find($taxCodeId);
        if (! $code instanceof TaxCode) {
            return null;
        }
        $version = $code->versions()->whereDate('effective_from', '<=', $taxPointDate)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $taxPointDate))->first();
        if (! $version instanceof TaxCodeVersion) {
            return null;
        }

        return new TaxSnapshot(['tax_code_id' => $code->id, 'tax_code_version_id' => $version->id, 'code' => $code->code, 'jurisdiction' => $code->jurisdiction, 'treatment' => $version->treatment, 'rate' => $version->rate, 'recoverable' => $version->recoverable, 'calculation_method' => $version->calculation_method, 'gl_mapping' => $version->gl_mapping, 'return_box_mapping' => $version->return_box_mapping, 'effective_from' => $version->effective_from->toDateString(), 'effective_to' => $version->effective_to?->toDateString()]);
    }
}
