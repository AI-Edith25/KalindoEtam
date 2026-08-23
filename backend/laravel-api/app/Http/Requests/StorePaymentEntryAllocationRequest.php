<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentEntryAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.accounts_payable_id' => ['required', 'uuid', 'exists:accounts_payables,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
