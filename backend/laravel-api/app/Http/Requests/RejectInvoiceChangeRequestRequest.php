<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Rejection requires a reason — approval doesn't (mirrors RejectApprovalRequest). */
class RejectInvoiceChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['required', 'string', 'max:1000'],
        ];
    }
}
