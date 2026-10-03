<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Editable — CustomerService::create() only auto-fills this when omitted/blank, a
            // supplied value is honored (still validated unique below). whereNull('deleted_at') —
            // see StoreChartOfAccountRequest's own comment.
            'customer_code' => ['nullable', 'string', 'max:255', Rule::unique('customers', 'customer_code')->whereNull('deleted_at')],
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'no_ktp' => ['nullable', 'string', 'max:50'],
            'no_npwp' => ['nullable', 'string', 'max:50'],
            'area' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'sales_person_id' => ['nullable', 'uuid', 'exists:sales_persons,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'terms_of_payment_id' => ['nullable', 'uuid', 'exists:terms_of_payments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
