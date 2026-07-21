<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\RejectsUnknownFields;
use App\Support\Documents\ExactDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class M3SettlementRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $allowed = array_values(array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.')));
        $this->rejectUnknownFields($validator, $allowed);

        $validator->after(function (Validator $validator): void {
            $route = $this->route()?->getName();
            if (in_array($route, ['settlement.receipts.store', 'settlement.payments.store'], true)) {
                $unapplied = $this->input('unapplied_amount.amount');
                if (is_string($unapplied) && ! $this->has('party_credit_expected_version')) {
                    try {
                        if (ExactDecimal::positive($unapplied)) {
                            $validator->errors()->add('party_credit_expected_version', 'The party-credit projection version is required for unapplied credit.');
                        }
                    } catch (InvalidArgumentException) {
                        // The field-level Money rule provides the canonical validation error.
                    }
                }
            }
            if ($route === 'settlement.credits.apply') {
                $partyType = $this->input('party_type');
                foreach ((array) $this->input('allocations', []) as $index => $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $expected = $partyType === 'customer' ? 'invoice_id' : 'bill_id';
                    $prohibited = $partyType === 'customer' ? 'bill_id' : 'invoice_id';
                    if (! isset($line[$expected]) || isset($line[$prohibited])) {
                        $validator->errors()->add("allocations.{$index}", 'Each allocation must name exactly one document of the selected party type.');
                    }
                }
            }
        });
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'settlement.receipts.store' => $this->cashRules('customer_id', 'invoice_id'),
            'settlement.payments.store' => $this->cashRules('vendor_id', 'bill_id'),
            'settlement.credits.apply' => $this->creditApplicationRules(),
            'settlement.credits.refund' => $this->creditRefundRules(),
            default => [],
        };
    }

    /** @return array<string,list<string>> */
    private function cashRules(string $partyKey, string $documentKey): array
    {
        return [
            $partyKey => ['required', 'uuid'],
            'settlement_date' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['required', 'uuid'],
            ...$this->moneyRules('gross_amount', true),
            ...$this->moneyRules('bank_amount'),
            ...$this->moneyRules('withholding_amount'),
            ...$this->moneyRules('unapplied_amount'),
            'rate_record_id' => ['present', 'nullable', 'uuid'],
            'party_credit_expected_version' => ['sometimes', 'integer', 'min:0'],
            'withholding_lines' => ['present', 'array'],
            'withholding_lines.*' => ['array:withholding_code,amount'],
            'withholding_lines.*.withholding_code' => ['required', 'string', 'max:100'],
            'withholding_lines.*.amount' => ['required', 'array:amount,currency'],
            'withholding_lines.*.amount.amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/'],
            'withholding_lines.*.amount.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'allocations' => ['present', 'array'],
            'allocations.*' => ["array:{$documentKey},applied_amount,expected_version"],
            "allocations.*.{$documentKey}" => ['required', 'uuid', 'distinct'],
            'allocations.*.applied_amount' => ['required', 'array:amount,currency'],
            'allocations.*.applied_amount.amount' => ['required', 'string', 'regex:/^(?=.*[1-9])(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/'],
            'allocations.*.applied_amount.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'allocations.*.expected_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string,list<string>> */
    private function creditApplicationRules(): array
    {
        return [
            'party_type' => ['required', 'in:customer,vendor'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'application_date' => ['required', 'date_format:Y-m-d'],
            ...$this->sourceRules(),
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['array:invoice_id,bill_id,credit_tranche_id,applied_amount,expected_version'],
            'allocations.*.invoice_id' => ['sometimes', 'uuid'],
            'allocations.*.bill_id' => ['sometimes', 'uuid'],
            'allocations.*.credit_tranche_id' => ['required', 'uuid'],
            'allocations.*.applied_amount' => ['required', 'array:amount,currency'],
            'allocations.*.applied_amount.amount' => ['required', 'string', 'regex:/^(?=.*[1-9])(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/'],
            'allocations.*.applied_amount.currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'allocations.*.expected_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string,list<string>> */
    private function creditRefundRules(): array
    {
        return [
            'party_type' => ['required', 'in:customer,vendor'],
            'refund_date' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['required', 'uuid'],
            ...$this->moneyRules('refund_amount', true),
            ...$this->moneyRules('expected_available_balance'),
            'rate_record_id' => ['present', 'nullable', 'uuid'],
            ...$this->sourceRules(),
        ];
    }

    /** @return array<string,list<string>> */
    private function sourceRules(): array
    {
        return [
            'credit_sources' => ['required', 'array', 'min:1'],
            'credit_sources.*' => ['array:credit_tranche_id,amount,expected_version'],
            'credit_sources.*.credit_tranche_id' => ['required', 'uuid', 'distinct'],
            'credit_sources.*.amount' => ['required', 'array:amount,currency'],
            'credit_sources.*.amount.amount' => ['required', 'string', 'regex:/^(?=.*[1-9])(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/'],
            'credit_sources.*.amount.currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'credit_sources.*.expected_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string,list<string>> */
    private function moneyRules(string $field, bool $positive = false): array
    {
        $pattern = $positive ? '/^(?=.*[1-9])(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/' : '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/';

        return [
            $field => ['required', 'array:amount,currency'],
            "{$field}.amount" => ['required', 'string', "regex:{$pattern}"],
            "{$field}.currency" => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
        ];
    }
}
