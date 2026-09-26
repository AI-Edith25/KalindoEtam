<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBankStatementLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'uuid', 'exists:chart_of_accounts,id'],
            'date' => ['required', 'date'],
            'view' => ['nullable', Rule::in(['import', 'system'])],
        ];
    }
}
