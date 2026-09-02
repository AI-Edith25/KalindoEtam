<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // item_id/price_zone_id are immutable once set (delete + recreate to move an override to
        // a different item/zone) — only the rate itself is editable, so the composite-unique
        // check from StoreItemPriceRequest never needs an ->ignore() companion here.
        return [
            'rate' => ['required', 'numeric', 'min:0'],
        ];
    }
}
