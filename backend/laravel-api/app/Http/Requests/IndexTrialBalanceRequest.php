<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Same filter contract as IndexGeneralLedgerRequest — passed straight through to GeneralLedgerService. See docs/TRIAL_BALANCE_DESIGN.md §4. */
class IndexTrialBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'reference_type' => ['sometimes', 'nullable', 'string'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'company_id' => ['sometimes', 'nullable', 'uuid', 'exists:companies,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
