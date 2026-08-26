<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPurchaseReturnRequest extends FormRequest
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
            'reason' => ['sometimes', 'nullable', Rule::enum(PurchaseReturnReason::class)],
            'supplier_id' => ['sometimes', 'nullable', 'uuid', 'exists:suppliers,id'],
            'purchase_invoice_id' => ['sometimes', 'nullable', 'uuid', 'exists:purchase_invoices,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
