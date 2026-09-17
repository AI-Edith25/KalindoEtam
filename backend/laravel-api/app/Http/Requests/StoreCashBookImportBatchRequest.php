<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashBookImportBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls'],
            'view' => ['required', Rule::in(['all', 'receipt', 'payment'])],
            'confirm_journal_type' => ['sometimes', 'boolean'],
        ];
    }
}
