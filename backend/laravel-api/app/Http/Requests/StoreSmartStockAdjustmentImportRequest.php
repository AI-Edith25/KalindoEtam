<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSmartStockAdjustmentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls'],
            // Fallback Adjustment Date for rows whose own Date column is blank — same role as
            // SmartOpeningStockImportService's metadata_cutoff_date, just named for this document.
            'metadata_adjustment_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
