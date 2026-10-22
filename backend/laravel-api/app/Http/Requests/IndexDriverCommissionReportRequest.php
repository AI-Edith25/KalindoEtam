<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMethod;
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
            // One of DeliveryMethod's 4 values, or "all" — defaults to DELIVERED_WITH_LABOR, see DriverCommissionReportService.
            'delivery_status' => ['sometimes', 'nullable', Rule::in([...array_map(fn (DeliveryMethod $case) => $case->value, DeliveryMethod::cases()), 'all'])],
        ];
    }
}
