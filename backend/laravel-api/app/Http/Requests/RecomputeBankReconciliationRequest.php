<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecomputeBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'tolerance_days' => ['nullable', 'integer', 'min:0', 'max:7'],
        ];
    }
}
