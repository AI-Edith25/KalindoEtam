<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexBankReconciliationMatchingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'bank_account_id' => ['required', 'uuid', 'exists:chart_of_accounts,id'],
        ];
    }
}
