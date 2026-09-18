<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesPurchaseJournalImportBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls'],
            'view' => ['required', Rule::in(['sales_invoice', 'sales_credit_note', 'purchase_invoice', 'purchase_return'])],
            'confirm_journal_type' => ['sometimes', 'boolean'],
            'duplicate_policy' => ['sometimes', Rule::in(['skip', 'create_anyway'])],
        ];
    }
}
