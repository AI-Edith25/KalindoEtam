<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors IndexTrialBalanceRequest's filter contract except as_of_date is
 * required — a Balance Sheet is a point-in-time snapshot, never an
 * unbounded/undated report. See docs/BALANCE_SHEET_DESIGN.md §7.
 */
class IndexBalanceSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'as_of_date' => ['required', 'date'],
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'reference_type' => ['sometimes', 'nullable', 'string'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'company_id' => ['sometimes', 'nullable', 'uuid', 'exists:companies,id'],
        ];
    }
}
