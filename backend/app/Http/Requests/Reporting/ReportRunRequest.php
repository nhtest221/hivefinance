<?php

namespace App\Http\Requests\Reporting;

use App\Http\Requests\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** API Contracts §13.4/§13.6: POST /v1/report-runs request shape. */
final class ReportRunRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator, ['report_type', 'period_ref', 'as_of', 'filters']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'report_type' => ['required', 'string', 'in:trial_balance,general_ledger,profit_and_loss,balance_sheet,ar_ageing,ap_ageing,tax_summary,fx_revaluation,cash_view'],
            'period_ref' => ['sometimes', 'nullable', 'string'],
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'filters' => ['sometimes', 'array'],
        ];
    }
}
