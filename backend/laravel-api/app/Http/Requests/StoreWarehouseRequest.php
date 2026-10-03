<?php

namespace App\Http\Requests;

use App\Enums\WarehouseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'code' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'code')->whereNull('deleted_at')],
            'warehouse_type' => ['required', Rule::enum(WarehouseType::class)],
        ];
    }
}
