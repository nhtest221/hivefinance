<?php

namespace App\Http\Requests\Ledger;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ReverseJournalRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['entry_date', 'reason']);
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
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'regex:/\S/', 'max:2000'],
        ];
    }
}
