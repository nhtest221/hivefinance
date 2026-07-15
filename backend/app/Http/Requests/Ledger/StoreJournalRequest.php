<?php

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreJournalRequest extends FormRequest
{
    public function withValidator(Validator $validator): void
    {
        $unknown = array_diff(array_keys($this->all()), ['entry_date', 'entry_type', 'narration', 'reference', 'lines']);
        if ($unknown !== []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('body', 'Unknown fields are not allowed: '.implode(', ', $unknown)));
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'entry_type' => ['sometimes', 'string', 'in:manual'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*' => ['required', 'array:account_id,description,debit,credit'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.debit' => ['nullable', 'array:amount,currency'],
            'lines.*.debit.amount' => ['required_with:lines.*.debit', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.debit.currency' => ['required_with:lines.*.debit', 'string', 'size:3', 'uppercase'],
            'lines.*.credit' => ['nullable', 'array:amount,currency'],
            'lines.*.credit.amount' => ['required_with:lines.*.credit', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.credit.currency' => ['required_with:lines.*.credit', 'string', 'size:3', 'uppercase'],
        ];
    }
}
