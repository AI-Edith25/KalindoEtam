<?php

namespace App\Http\Requests;

use App\Enums\MiscellaneousChargeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMiscellaneousItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'misc_code' => ['required', 'string', 'max:255', 'unique:miscellaneous_items,misc_code'],
            'description' => ['required', 'string', 'max:255'],
            'rate' => ['sometimes', 'numeric', 'min:0'],
            'uom_id' => ['nullable', 'uuid', 'exists:uoms,id'],
            'charge_type' => ['required', Rule::enum(MiscellaneousChargeType::class)],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'sales_account_id' => ['required', 'uuid', 'exists:chart_of_accounts,id'],
            'purchase_account_id' => ['required', 'uuid', 'exists:chart_of_accounts,id'],
        ];
    }
}
