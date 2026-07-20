<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class M2DocumentRequest extends FormRequest
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
        if (in_array($this->route()?->getName(), ['customers.update', 'vendors.update', 'invoices.update', 'bills.update'], true) && $this->all() === []) {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('body', 'At least one approved draft field is required.'));
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'customers.store' => $this->customerRules(true),
            'customers.update' => $this->customerRules(false),
            'vendors.store' => $this->vendorRules(true),
            'vendors.update' => $this->vendorRules(false),
            'invoices.store' => $this->invoiceRules(true),
            'invoices.update' => $this->invoiceRules(false),
            'bills.store' => $this->billRules(true),
            'bills.update' => $this->billRules(false),
            'expenses.store' => $this->expenseRules(),
            default => [],
        };
    }

    /** @return array<string, list<string>> */
    private function customerRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255', 'not_regex:/^\s*$/'],
            'type' => [$presence, 'string', 'in:local,foreign'],
            'jurisdiction' => ['sometimes', 'nullable', 'string', 'size:2', 'required_with:tax_identifier'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default_currency' => [$presence, 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'payment_terms' => [$presence, 'string', 'max:100'],
            ...$this->contactAddressRules(),
        ];
    }

    /** @return array<string, list<string>> */
    private function vendorRules(bool $required): array
    {
        $rules = $this->customerRules($required);
        unset($rules['type']);
        $rules['bank_details'] = ['sometimes', 'nullable', 'array:account_name,institution_name,account_identifier,routing_identifier'];
        $rules['bank_details.account_name'] = ['required_with:bank_details', 'string', 'max:255'];
        $rules['bank_details.institution_name'] = ['required_with:bank_details', 'string', 'max:255'];
        $rules['bank_details.account_identifier'] = ['required_with:bank_details', 'string', 'max:255'];
        $rules['bank_details.routing_identifier'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }

    /** @return array<string, list<string>> */
    private function invoiceRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'customer_id' => [$presence, 'uuid'],
            'invoice_date' => [$presence, 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'currency' => [$presence, 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'payment_instructions_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rate_record_id' => ['sometimes', 'nullable', 'uuid'],
            'lines' => [$presence, 'array', 'min:1'],
            'lines.*' => ['array:description,quantity,unit_price,tax_code_id'],
            'lines.*.description' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'lines.*.unit_price' => ['required', 'array:amount,currency'],
            'lines.*.unit_price.amount' => ['required', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'lines.*.unit_price.currency' => ['required', 'string', 'size:3'],
            'lines.*.tax_code_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, list<string>> */
    private function billRules(bool $required): array
    {
        $rules = $this->invoiceRules($required);
        $rules['vendor_id'] = $rules['customer_id'];
        unset($rules['customer_id']);
        $rules['bill_date'] = $rules['invoice_date'];
        unset($rules['invoice_date']);
        $rules['vendor_reference'] = $rules['reference'];
        unset($rules['reference']);
        unset($rules['payment_instructions_ref']);
        $rules['lines.*'] = ['array:description,quantity,unit_price,expense_account_id,tax_code_id'];
        $rules['lines.*.expense_account_id'] = ['required', 'uuid'];
        $rules['sbu_allocations'] = [$required ? 'required' : 'sometimes', 'array', 'min:1'];
        $rules['sbu_allocations.*'] = ['array:sbu_code,weight'];
        $rules['sbu_allocations.*.sbu_code'] = ['required', 'string', 'max:100'];
        $rules['sbu_allocations.*.weight'] = ['required', 'string', 'regex:/^(?:0\.[0-9]{1,4}|1\.0{1,4})$/'];
        $rules['ait'] = ['sometimes', 'nullable', 'array:amount,currency'];
        $rules['ait.amount'] = ['required_with:ait', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'];
        $rules['ait.currency'] = ['required_with:ait', 'string', 'size:3'];
        $rules['vds'] = $rules['ait'];
        $rules['vds.amount'] = $rules['ait.amount'];
        $rules['vds.currency'] = $rules['ait.currency'];

        return $rules;
    }

    /** @return array<string, list<string>> */
    private function expenseRules(): array
    {
        return [
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/'],
            'vendor_id' => ['nullable', 'uuid'],
            'category_account_id' => ['required', 'uuid'],
            'settlement_type' => ['required', 'string', 'in:cash,accrued'],
            'bank_account_id' => ['nullable', 'uuid'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'amount' => ['required', 'array:amount,currency'],
            'amount.amount' => ['required', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'amount.currency' => ['required', 'string', 'size:3'],
            'tax_code_id' => ['nullable', 'uuid'],
            'ait' => ['nullable', 'array:amount,currency'],
            'ait.amount' => ['required_with:ait', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/'],
            'ait.currency' => ['required_with:ait', 'string', 'size:3'],
            'sbu_allocations' => ['required', 'array', 'min:1'],
            'sbu_allocations.*' => ['array:sbu_code,weight'],
            'sbu_allocations.*.sbu_code' => ['required', 'string', 'max:100'],
            'sbu_allocations.*.weight' => ['required', 'string', 'regex:/^(?:0\.[0-9]{1,4}|1\.0{1,4})$/'],
        ];
    }

    /** @return array<string, list<string>> */
    private function contactAddressRules(): array
    {
        return [
            'contact' => ['sometimes', 'nullable', 'array:email,phone'],
            'contact.email' => ['nullable', 'email:rfc', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:64'],
            'address' => ['sometimes', 'nullable', 'array:line_1,line_2,city,region,postal_code,country_code'],
            'address.line_1' => ['required_with:address', 'string', 'max:255'],
            'address.line_2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['required_with:address', 'string', 'max:255'],
            'address.region' => ['nullable', 'string', 'max:255'],
            'address.postal_code' => ['nullable', 'string', 'max:32'],
            'address.country_code' => ['required_with:address', 'string', 'size:2'],
        ];
    }
}
