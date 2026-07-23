<?php

namespace App\Http\Requests\Reconciliation;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** API Contracts §14.8: POST /v1/reconciliations/{id}/lines/{lineId}/bank-entry request shape. */
final class CreateBankEntryRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['offset_account_id', 'narration']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'offset_account_id' => ['required', 'string'],
            'narration' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
