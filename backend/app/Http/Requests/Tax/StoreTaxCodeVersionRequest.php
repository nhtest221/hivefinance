<?php

namespace App\Http\Requests\Tax;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreTaxCodeVersionRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['treatment', 'rate', 'recoverable', 'calculation_method', 'gl_mapping', 'return_box_mapping', 'effective_from', 'effective_to']);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['treatment' => ['required', 'in:standard,zero_rated,exempt'], 'rate' => ['required', 'string', 'regex:/^\d+(\.\d{1,8})?$/'], 'recoverable' => ['required', 'boolean'], 'calculation_method' => ['required', 'string', 'max:100'], 'gl_mapping' => ['required', 'array'], 'gl_mapping.*' => ['nullable', 'uuid'], 'return_box_mapping' => ['required', 'array'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from']];
    }
}
