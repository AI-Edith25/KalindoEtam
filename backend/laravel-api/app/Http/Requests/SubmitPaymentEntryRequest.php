<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Submit previously took no body at all — supplier/general_expense vouchers still don't need
 * one (their `lines` rules below are simply skipped by `required_if`, and PaymentEntryService::
 * submit()'s default `$lines = []` param covers the rest). Only payment_type=mixed actually
 * needs a body: the full set of allocation lines, decided once at submit time — see
 * PaymentEntryService::submitMixed()'s own doc comment for why they aren't a separate resource.
 */
class SubmitPaymentEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required_if:payment_type,mixed', 'array', 'min:1'],
            'lines.*.type' => ['required_with:lines', Rule::in(['supplier', 'expense'])],
            'lines.*.accounts_payable_id' => ['required_if:lines.*.type,supplier', 'nullable', 'uuid', 'exists:accounts_payables,id'],
            'lines.*.expense_account_id' => ['required_if:lines.*.type,expense', 'nullable', 'uuid', 'exists:chart_of_accounts,id'],
            'lines.*.description' => ['required_if:lines.*.type,expense', 'nullable', 'string', 'max:255'],
            'lines.*.branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    /**
     * `payment_type` isn't a field this request accepts input for (it's immutable, set at
     * create time) — merged in here purely so the `required_if:payment_type,mixed` rule above
     * can see it, since a FormRequest's `required_if` only reads from the request's own payload.
     */
    protected function prepareForValidation(): void
    {
        $paymentEntry = $this->route('paymentEntry');

        if ($paymentEntry) {
            $this->merge(['payment_type' => $paymentEntry->payment_type?->value]);
        }
    }
}
