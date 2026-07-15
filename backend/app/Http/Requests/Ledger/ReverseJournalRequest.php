<?php

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseJournalRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
