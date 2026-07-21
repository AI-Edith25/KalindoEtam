<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors IndexTrialBalanceRequest exactly except date_from is required —
 * an unbounded range would sum all income since inception under the "this
 * period" label, which is meaningless for an Income Statement. See
 * docs/PROFIT_LOSS_DESIGN.md §6.
 */
class IndexProfitLossRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'reference_type' => ['sometimes', 'nullable', 'string'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'company_id' => ['sometimes', 'nullable', 'uuid', 'exists:companies,id'],
        ];
    }
}
