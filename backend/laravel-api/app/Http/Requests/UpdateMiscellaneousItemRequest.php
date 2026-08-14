<?php

namespace App\Http\Requests;

use App\Enums\MiscellaneousChargeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMiscellaneousItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'misc_code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('miscellaneous_items', 'misc_code')->ignore($this->route('miscellaneous_item'))],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'rate' => ['sometimes', 'numeric', 'min:0'],
            'uom_id' => ['nullable', 'uuid', 'exists:uoms,id'],
            'charge_type' => ['sometimes', 'required', Rule::enum(MiscellaneousChargeType::class)],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'sales_account_id' => ['sometimes', 'required', 'uuid', 'exists:chart_of_accounts,id'],
            'purchase_account_id' => ['sometimes', 'required', 'uuid', 'exists:chart_of_accounts,id'],
        ];
    }
}
