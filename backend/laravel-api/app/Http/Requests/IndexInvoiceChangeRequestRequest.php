<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexInvoiceChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'uuid', 'exists:invoices,id'],
        ];
    }
}
