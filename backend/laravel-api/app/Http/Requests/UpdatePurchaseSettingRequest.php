<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Null/0 = no upper bound — enforced in the service, not here.
            'weight_over_receipt_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
        ];
    }
}
