<?php

namespace App\Http\Requests\Ledger;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateAccountRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['name', 'description', 'type']);
        if (array_intersect(array_keys($this->all()), ['name', 'description', 'type']) === []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('body', 'At least one mutable field is required.'));
        }
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => ['sometimes', 'required', 'string', 'in:asset,liability,equity,revenue,expense'],
        ];
    }
}
