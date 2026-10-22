<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexDriverCommissionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'fleet_id' => ['sometimes', 'nullable', 'uuid', 'exists:fleets,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            // "included" (default) | "excluded" | "all" — see DriverCommissionReportService.
            'status' => ['sometimes', 'nullable', Rule::in(['included', 'excluded', 'all'])],
        ];
    }
}
