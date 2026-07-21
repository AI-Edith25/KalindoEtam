<?php

namespace App\Http\Requests;

use App\Enums\PeriodStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fiscal_year_id' => ['sometimes', 'nullable', 'uuid', 'exists:fiscal_years,id'],
            'status' => ['sometimes', 'nullable', Rule::enum(PeriodStatus::class)],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
