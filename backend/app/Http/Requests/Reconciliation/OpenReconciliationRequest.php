<?php

namespace App\Http\Requests\Reconciliation;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** API Contracts §14.9: POST /v1/reconciliations request shape. */
final class OpenReconciliationRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['reconciliation_account_id', 'period_ref', 'opening_balance', 'closing_balance']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reconciliation_account_id' => ['required', 'string'],
            'period_ref' => ['required', 'string'],
            'opening_balance' => ['required', 'string'],
            'closing_balance' => ['required', 'string'],
        ];
    }
}
