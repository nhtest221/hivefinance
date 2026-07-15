<?php

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

final class StoreJournalRequest extends FormRequest
{
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
            'entry_type' => ['sometimes', 'string', 'in:manual,adjusting'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.debit' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.credit' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.currency' => ['required', 'string', 'size:3'],
        ];
    }
}
