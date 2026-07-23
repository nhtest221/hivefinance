<?php

namespace App\Http\Requests\Reconciliation;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** API Contracts §14.5: POST /v1/reconciliations/{id}/import request shape. */
final class ImportStatementRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['file_hash', 'column_mapping', 'lines']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'file_hash' => ['required', 'string'],
            'column_mapping' => ['sometimes', 'nullable', 'array'],
            'lines' => ['required', 'array'],
            'lines.*.source_line_identity' => ['required', 'string'],
            'lines.*.transaction_date' => ['required', 'date_format:Y-m-d'],
            'lines.*.narration' => ['required', 'string'],
            'lines.*.amount' => ['required', 'array'],
            'lines.*.amount.amount' => ['required', 'string'],
            'lines.*.amount.currency' => ['required', 'string', 'size:3'],
            'lines.*.external_bank_reference' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
