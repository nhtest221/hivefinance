<?php

namespace App\Http\Requests\Period;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PeriodRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $allowed = array_keys($this->rules());
        $this->rejectUnknownFields($validator, $allowed);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'periods.reopen' => [
                'reason_code' => ['required', 'string', 'max:100'],
                'narrative' => ['required', 'string', 'not_regex:/^\s*$/', 'max:2000'],
                'vat_unlock_requested' => ['required', 'boolean'],
            ],
            default => [],
        };
    }
}
