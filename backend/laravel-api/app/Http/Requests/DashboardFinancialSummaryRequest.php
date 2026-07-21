<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Defaults to month-to-date — ProfitLossService itself requires date_from (IndexProfitLossRequest), so the dashboard supplies a sensible default rather than forcing the caller to always pass one. See docs/DASHBOARD_DESIGN.md §3. */
class DashboardFinancialSummaryRequest extends FormRequest
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

    public function resolvedFilters(): array
    {
        return [
            'date_from' => $this->validated('date_from') ?? now()->startOfMonth()->toDateString(),
            'date_to' => $this->validated('date_to') ?? now()->toDateString(),
        ];
    }
}
