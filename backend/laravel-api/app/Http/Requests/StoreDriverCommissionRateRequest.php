<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDriverCommissionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fleet_id' => ['required', 'uuid', Rule::exists('fleets', 'id')->whereNull('deleted_at')],
            'item_id' => ['required', 'uuid', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'rate_per_zak' => ['required', 'numeric', 'min:0'],
            'effective_date' => ['required', 'date'],
        ];
    }
}
