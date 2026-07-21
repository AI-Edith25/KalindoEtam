<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors IndexProfitLossRequest exactly — date_from is required, a Cash
 * Flow Statement explains movement *during* a period, the same reasoning
 * that makes date_from mandatory for Profit & Loss. See
 * docs/CASH_FLOW_DESIGN.md §7.
 */
class IndexCashFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'company_id' => ['sometimes', 'nullable', 'uuid', 'exists:companies,id'],
        ];
    }
}
