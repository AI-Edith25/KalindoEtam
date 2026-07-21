<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Rejection requires a reason — approval doesn't (a plain sign-off needs no explanation, a rejection does). */
class RejectApprovalRequest extends FormRequest
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
