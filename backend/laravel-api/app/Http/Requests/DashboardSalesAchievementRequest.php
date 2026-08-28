<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Defaults to the current month — same "sensible default, caller may override" shape as DashboardFinancialSummaryRequest. */
class DashboardSalesAchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    public function resolvedMonth(): int
    {
        return (int) ($this->validated('month') ?? now()->month);
    }

    public function resolvedYear(): int
    {
        return (int) ($this->validated('year') ?? now()->year);
    }
}
