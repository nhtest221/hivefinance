<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class M4ANoteRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $allowed = array_keys($this->rules());
        $allowed = array_values(array_filter($allowed, fn (string $key): bool => ! str_contains($key, '.')));
        $this->rejectUnknownFields($validator, $allowed);
        if (in_array($this->route()?->getName(), ['credit-notes.update', 'debit-notes.update'], true) && $this->all() === []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('body', 'At least one approved draft field is required.'));
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'credit-notes.store', 'debit-notes.store' => $this->createRules(),
            'credit-notes.update', 'debit-notes.update' => $this->updateRules(),
            default => [],
        };
    }

    /**
     * party_type/document_type are intentionally validated only as non-blank strings here.
     * Their fixed canonical values are a business invariant (API Contracts §12.2
     * `note_direction_mismatch`), not a structural validation rule — rejecting a wrong
     * value at this layer would surface it as a generic 400 instead of the frozen
     * 422 invariant_violation with rule `note_direction_mismatch`.
     *
     * @return array<string, list<string>>
     */
    private function createRules(): array
    {
        return [
            'party_type' => ['required', 'string'],
            'document_type' => ['required', 'string'],
            'party_id' => ['required', 'uuid'],
            'source_document_id' => ['required', 'uuid'],
            'source_document_expected_version' => ['required', 'integer', 'min:1'],
            'note_date' => ['required', 'date_format:Y-m-d'],
            'reason_code' => ['required', 'string', 'max:100'],
            'narrative' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.source_line_id' => ['required', 'uuid'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines.*.net_amount' => ['required', 'array'],
            'lines.*.net_amount.amount' => ['required', 'string', 'regex:/^[0-9]+(\.[0-9]{1,4})?$/'],
            'lines.*.net_amount.currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    /** @return array<string, list<string>> */
    private function updateRules(): array
    {
        $rules = $this->createRules();

        return array_map(function (array $rule): array {
            $rule = array_values(array_filter($rule, fn (string $r): bool => $r !== 'required'));
            array_unshift($rule, 'sometimes');

            return $rule;
        }, $rules);
    }
}
