<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class M4ANoteDispositionRequest extends FormRequest
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
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'credit-notes.apply', 'debit-notes.apply' => $this->applyRules(),
            'credit-notes.hold', 'debit-notes.hold' => $this->holdRules(),
            'credit-notes.refund', 'debit-notes.refund' => $this->refundRules(),
            'credit-notes.reverse', 'debit-notes.reverse' => $this->reverseRules(),
            default => [],
        };
    }

    /** @return array<string, list<string>> */
    private function applyRules(): array
    {
        return [
            'application_date' => ['required', 'date_format:Y-m-d'],
            'source' => ['required', 'string', 'in:undisposed,held'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.document_id' => ['required', 'uuid'],
            'allocations.*.amount' => ['required', 'array'],
            'allocations.*.amount.amount' => ['required', 'string', 'regex:/^[0-9]+(\.[0-9]{1,4})?$/'],
            'allocations.*.amount.currency' => ['required', 'string', 'size:3'],
            'allocations.*.expected_version' => ['required', 'integer', 'min:1'],
            'credit_sources' => ['sometimes', 'array'],
            'credit_sources.*.credit_tranche_id' => ['required_with:credit_sources', 'uuid'],
            'credit_sources.*.amount' => ['required_with:credit_sources', 'array'],
            'credit_sources.*.amount.amount' => ['required_with:credit_sources', 'string', 'regex:/^[0-9]+(\.[0-9]{1,4})?$/'],
            'credit_sources.*.amount.currency' => ['required_with:credit_sources', 'string', 'size:3'],
            'credit_sources.*.expected_version' => ['required_with:credit_sources', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, list<string>> */
    private function holdRules(): array
    {
        return [
            'hold_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'array'],
            'amount.amount' => ['required', 'string', 'regex:/^[0-9]+(\.[0-9]{1,4})?$/'],
            'amount.currency' => ['required', 'string', 'size:3'],
        ];
    }

    /** @return array<string, list<string>> */
    private function refundRules(): array
    {
        return [
            'refund_date' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['required', 'uuid'],
            'refund_amount' => ['required', 'array'],
            'refund_amount.amount' => ['required', 'string', 'regex:/^[0-9]+(\.[0-9]{1,4})?$/'],
            'refund_amount.currency' => ['required', 'string', 'size:3'],
            'expected_available_balance' => ['required', 'array'],
            'expected_available_balance.amount' => ['required', 'string', 'regex:/^[0-9]+(\.[0-9]{1,4})?$/'],
            'expected_available_balance.currency' => ['required', 'string', 'size:3'],
            'rate_record_id' => ['sometimes', 'nullable', 'uuid'],
            'credit_sources' => ['required', 'array', 'min:1'],
            'credit_sources.*.credit_tranche_id' => ['required', 'uuid'],
            'credit_sources.*.amount' => ['required', 'array'],
            'credit_sources.*.amount.amount' => ['required', 'string', 'regex:/^[0-9]+(\.[0-9]{1,4})?$/'],
            'credit_sources.*.amount.currency' => ['required', 'string', 'size:3'],
            'credit_sources.*.expected_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, list<string>> */
    private function reverseRules(): array
    {
        return [
            'reversal_date' => ['required', 'date_format:Y-m-d'],
            'reason_code' => ['required', 'string', 'max:100'],
            'narrative' => ['required', 'string', 'not_regex:/^\s*$/', 'max:2000'],
            // "present" (not "required"): API Contracts §12.3.9 — complete arrays are
            // required, but "empty arrays only when none apply" means [] is valid, and
            // Laravel's `required` rule rejects an empty array.
            'document_versions' => ['present', 'array'],
            'document_versions.*.document_id' => ['required', 'uuid'],
            'document_versions.*.expected_version' => ['required', 'integer', 'min:1'],
            'credit_source_versions' => ['present', 'array'],
            'credit_source_versions.*.credit_tranche_id' => ['required', 'uuid'],
            'credit_source_versions.*.expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
