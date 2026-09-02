<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => [
                'required', 'uuid', 'exists:items,id',
                Rule::unique('item_prices')->where(fn ($query) => $query->where('price_zone_id', $this->input('price_zone_id'))),
            ],
            'price_zone_id' => ['required', 'uuid', 'exists:price_zones,id'],
            'rate' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.unique' => 'This item already has a price for the selected zone.',
        ];
    }
}
