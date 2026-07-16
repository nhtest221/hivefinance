<?php

namespace App\Http\Requests\CurrencyFx;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreRateRecordRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['base_currency', 'quote_currency', 'rate', 'effective_date', 'source', 'is_override', 'override_reason']);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['base_currency' => ['required', 'string', 'size:3', 'uppercase'], 'quote_currency' => ['required', 'string', 'size:3', 'uppercase'], 'rate' => ['required', 'string', 'regex:/^[0-9]+(\.[0-9]{1,8})?$/', 'not_in:0,0.0,0.00,0.000,0.0000,0.00000,0.000000,0.0000000,0.00000000'], 'effective_date' => ['required', 'date_format:Y-m-d'], 'source' => ['required', 'string', 'max:100'], 'is_override' => ['required', 'boolean'], 'override_reason' => ['nullable', 'string', 'max:2000']];
    }
}
