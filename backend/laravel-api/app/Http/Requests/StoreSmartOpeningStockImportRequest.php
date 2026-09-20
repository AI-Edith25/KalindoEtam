<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSmartOpeningStockImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls'],
            // Fallback Cutoff Date for rows whose own Date column is blank — taken from the
            // file's own "Date From"/"Date To" metadata line when the user chooses to use it.
            // Never required: a file where every row already carries its own date needs none.
            'metadata_cutoff_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
