<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Mirrors DecideApprovalRequest — approval's remarks are optional context, not a requirement. */
class DecideInvoiceChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
