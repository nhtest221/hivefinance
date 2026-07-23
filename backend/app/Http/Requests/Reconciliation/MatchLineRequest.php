<?php

namespace App\Http\Requests\Reconciliation;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** API Contracts §14.7: POST /v1/reconciliations/{id}/lines/{lineId}/match request shape. */
final class MatchLineRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['allocation_ids', 'line_ids']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'allocation_ids' => ['required', 'array', 'min:1'],
            'allocation_ids.*' => ['string'],
            'line_ids' => ['sometimes', 'array'],
            'line_ids.*' => ['string'],
        ];
    }
}
