<?php

namespace App\Http\Requests;

use App\Models\SalesTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateSalesTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_person_id' => ['sometimes', 'required', 'uuid', 'exists:sales_persons,id'],
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'period_month' => ['sometimes', 'required', 'integer', 'between:1,12'],
            'period_year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'target_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->assertUniquePeriod();
    }

    /** Same reasoning as StoreSalesTargetRequest::assertUniquePeriod() — see that class's docblock. Unset fields fall back to the existing record's own value, matching how a partial PUT is expected to behave. */
    protected function assertUniquePeriod(): void
    {
        /** @var SalesTarget $current */
        $current = $this->route('sales_target');

        $salesPersonId = $this->input('sales_person_id', $current->sales_person_id);
        $periodMonth = $this->input('period_month', $current->period_month);
        $periodYear = $this->input('period_year', $current->period_year);
        $branchId = $this->has('branch_id') ? $this->input('branch_id') : $current->branch_id;

        $exists = SalesTarget::query()
            ->where('id', '!=', $current->id)
            ->where('sales_person_id', $salesPersonId)
            ->where('period_month', $periodMonth)
            ->where('period_year', $periodYear)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'sales_person_id' => ['This sales person already has a target for the selected period and branch.'],
            ]);
        }
    }
}
