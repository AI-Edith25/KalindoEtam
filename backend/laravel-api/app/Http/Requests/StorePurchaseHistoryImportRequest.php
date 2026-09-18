<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseHistoryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls'],
            // Neither source file carries a warehouse or a real item-level breakdown for Purchase
            // Order Tracking rows — these aren't a column to map, they're real missing input only
            // the user can supply once, upfront, for the whole run.
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'placeholder_item_id' => ['required', 'uuid', 'exists:items,id'],
        ];
    }
}
