<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Generates 12 monthly Accounting Periods (all Open) starting from start_date — see PeriodManagementService::createFiscalYear(). */
class StoreFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
        ];
    }
}
