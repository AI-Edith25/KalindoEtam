<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * warehouse_id is only a formality field for Goods rows (createDirectGoods()'s required column) —
 * stock never actually moves for an imported historical invoice, see SalesInvoiceImportService.
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
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
        ];
    }
}
