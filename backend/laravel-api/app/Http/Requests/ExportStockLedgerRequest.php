<?php

namespace App\Http\Requests;

use App\Enums\StockVoucherType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportStockLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'item_id' => ['sometimes', 'nullable', 'array'],
            'item_id.*' => ['uuid', 'exists:items,id'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'voucher_type' => ['sometimes', 'nullable', Rule::enum(StockVoucherType::class)],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
