<?php

namespace App\Http\Requests;

use App\Models\SalesTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreSalesTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_person_id' => ['required', 'uuid', 'exists:sales_persons,id'],
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'target_amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    // One target per Sales Person per period(+Branch) — the composite unique index on
    // sales_targets already catches every distinct-branch duplicate, but MySQL treats NULL as
    // distinct in a unique index, so the "no branch" case needs this explicit whereNull check
    // (see assertUniquePeriod() below) to actually be caught — a bare Rule::unique() alone would
    // silently allow unlimited duplicate no-branch targets for the same person/period.

    protected function passedValidation(): void
    {
        $this->assertUniquePeriod();
    }

    protected function assertUniquePeriod(): void
    {
        $branchId = $this->input('branch_id');

        $exists = SalesTarget::query()
            ->where('sales_person_id', $this->input('sales_person_id'))
            ->where('period_month', $this->input('period_month'))
            ->where('period_year', $this->input('period_year'))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'sales_person_id' => ['This sales person already has a target for the selected period and branch.'],
            ]);
        }
    }
}
