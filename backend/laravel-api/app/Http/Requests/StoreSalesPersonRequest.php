<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Editable — SalesPersonService::create() only auto-fills this when omitted/blank, a
            // supplied value is honored (still validated unique below). whereNull('deleted_at') —
            // see StoreChartOfAccountRequest's own comment.
            'code' => ['nullable', 'string', 'max:255', Rule::unique('sales_persons', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
