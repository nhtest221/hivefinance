<?php

namespace App\Http\Requests\Reconciliation;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** API Contracts §14.4: POST /v1/reconciliation-accounts request shape. */
final class ReconciliationAccountRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['ledger_account_id', 'currency', 'display_name', 'masked_bank_identifier', 'reconciliation_enabled']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'ledger_account_id' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'display_name' => ['required', 'string'],
            'masked_bank_identifier' => ['sometimes', 'nullable', 'string'],
            'reconciliation_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
