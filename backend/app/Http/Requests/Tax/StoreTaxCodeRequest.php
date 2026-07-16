<?php

namespace App\Http\Requests\Tax;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreTaxCodeRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['code', 'name', 'jurisdiction']);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'min:1', 'max:32'], 'name' => ['required', 'string', 'min:1', 'max:255'], 'jurisdiction' => ['required', 'string', 'min:1', 'max:32']];
    }
}
