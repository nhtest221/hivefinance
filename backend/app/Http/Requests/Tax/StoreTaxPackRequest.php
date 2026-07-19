<?php

namespace App\Http\Requests\Tax;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreTaxPackRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['jurisdiction', 'name', 'tax_code_ids', 'return_template', 'policy']);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['jurisdiction' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:255'], 'tax_code_ids' => ['required', 'array', 'min:1'], 'tax_code_ids.*' => ['required', 'uuid', 'distinct'], 'return_template' => ['required', 'array', 'min:1'], 'policy' => ['required', 'array', 'min:1']];
    }
}
