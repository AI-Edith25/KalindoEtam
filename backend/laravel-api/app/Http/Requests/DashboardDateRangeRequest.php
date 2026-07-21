<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by the trend/movement chart endpoints (docs/DASHBOARD_DESIGN.md §5) — defaults to the last 30 days, mirroring DashboardDateRequest's own "sometimes, with a sensible default" shape. */
class DashboardDateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function resolvedDateFrom(): string
    {
        return $this->validated('date_from') ?? now()->subDays(29)->toDateString();
    }

    public function resolvedDateTo(): string
    {
        return $this->validated('date_to') ?? now()->toDateString();
    }
}
