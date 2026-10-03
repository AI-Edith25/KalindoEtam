<?php

namespace App\Http\Requests;

use App\Enums\QtyCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'item_code' => strtoupper($this->item_code),
        ]);
    }

    public function rules(): array
    {
        return [
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'item_code' => ['required', 'string', 'max:255', Rule::unique('items', 'item_code')->whereNull('deleted_at')],
            'item_name' => ['required', 'string', 'max:255'],
            'item_group_id' => ['required', 'uuid', 'exists:item_groups,id'],
            'uom_id' => ['required', 'uuid', 'exists:uoms,id'],
            'standard_rate' => ['sometimes', 'numeric', 'min:0'],
            'uoms' => ['sometimes', 'nullable', 'array'],
            'uoms.*.uom_id' => ['required', 'uuid', 'distinct', 'exists:uoms,id'],
            'uoms.*.conversion_factor' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'purchase_tax_id' => ['nullable', 'uuid', Rule::exists('taxes', 'id')->where('transaction_type', 'purchase')],
            'sales_tax_id' => ['nullable', 'uuid', Rule::exists('taxes', 'id')->where('transaction_type', 'sales')],
            'allow_over_receipt' => ['sometimes', 'boolean'],
            'qty_category' => ['sometimes', Rule::enum(QtyCategory::class)],
        ];
    }
}
