<?php

namespace App\Http\Requests;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** A legacy singular ?status=x caller is normalized to the same array shape the new multi-select filter sends, so both validate and filter identically. */
    protected function prepareForValidation(): void
    {
        if ($this->has('status') && ! is_array($this->status)) {
            $this->merge(['status' => array_filter(explode(',', (string) $this->status))]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'array'],
            'status.*' => [Rule::enum(DocumentStatus::class)],
            'reason' => ['sometimes', 'nullable', Rule::enum(CreditNoteReason::class)],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            'invoice_id' => ['sometimes', 'nullable', 'uuid', 'exists:invoices,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
