<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class M4AVoidRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, array_keys($this->rules()));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'void_date' => ['required', 'date_format:Y-m-d'],
            'reason_code' => ['required', 'string', 'max:100'],
            'narrative' => ['required', 'string', 'not_regex:/^\s*$/', 'max:2000'],
        ];
    }
}
