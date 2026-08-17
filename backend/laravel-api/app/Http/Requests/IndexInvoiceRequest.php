<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'invoice_type' => ['sometimes', 'nullable', Rule::enum(InvoiceType::class)],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'delivery_id' => ['sometimes', 'nullable', 'uuid', 'exists:deliveries,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            // Not 'boolean': axios serializes JS `true` to the query string "true", which
            // Laravel's boolean rule rejects (it only accepts 1/0/"1"/"0"/true/false). The
            // frontend only ever sends this param to enable the filter, never `=false`.
            'outstanding' => ['sometimes', 'nullable'],
        ];
    }
}
