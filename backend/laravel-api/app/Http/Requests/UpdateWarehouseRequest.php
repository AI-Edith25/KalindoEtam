<?php

namespace App\Http\Requests;

use App\Enums\WarehouseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('warehouses', 'code')->ignore($this->route('warehouse'))],
            'warehouse_type' => ['sometimes', 'required', Rule::enum(WarehouseType::class)],
        ];
    }
}
