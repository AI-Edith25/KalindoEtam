<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowSupplierOutstandingArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier' => ['nullable', 'string'],
            'invoice_date_from' => ['nullable', 'date'],
            'invoice_date_to' => ['nullable', 'date'],
            'due_date_from' => ['nullable', 'date'],
            'due_date_to' => ['nullable', 'date'],
            // No 'lunas' option -- this file only ever contains unpaid invoices.
            'status' => ['nullable', Rule::in(['outstanding', 'overdue'])],
        ];
    }
}
