<?php

namespace App\Http\Requests\CurrencyFx;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class RunRevaluationRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['period_ref']);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['period_ref' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/']];
    }
}
