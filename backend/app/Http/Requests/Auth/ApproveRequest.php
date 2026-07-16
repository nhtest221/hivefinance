<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->all() !== []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('body', 'The request body must be empty.'));
        }
    }
}
