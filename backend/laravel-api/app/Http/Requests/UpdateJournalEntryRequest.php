<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'posting_date' => ['sometimes', 'required', 'date'],
            'description' => ['nullable', 'string'],
            'lines' => ['sometimes', 'array', 'min:2'],
            'lines.*.chart_of_account_id' => ['required_with:lines', 'uuid', 'exists:chart_of_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string'],
        ];
    }
}
