<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * No warehouse_id here — Location is resolved per invoice from the file's own LOCATION column
 * (SalesInvoiceHistoryParser/SalesInvoiceImportService::classifyLocations()), since a single file
 * legitimately mixes invoices from multiple real-world locations.
 */
class StoreSalesInvoiceHistoryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls'],
        ];
    }
}
