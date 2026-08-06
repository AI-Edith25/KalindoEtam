<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Same shape as IndexTrialBalanceRequest — passed straight through to AccountsReceivableService::summarizeAging(). */
class IndexAccountsReceivableAgingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'as_of_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
