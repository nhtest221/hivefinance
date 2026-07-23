<?php

namespace App\Http\Requests\Reconciliation;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** API Contracts §14.4: PATCH /v1/reconciliation-accounts/{id} request shape. */
final class UpdateReconciliationAccountRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['display_name', 'masked_bank_identifier', 'reconciliation_enabled', 'column_mapping']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'string'],
            'masked_bank_identifier' => ['sometimes', 'nullable', 'string'],
            'reconciliation_enabled' => ['sometimes', 'boolean'],
            'column_mapping' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
