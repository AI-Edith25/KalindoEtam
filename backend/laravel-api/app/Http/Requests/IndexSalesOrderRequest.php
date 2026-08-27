<?php

namespace App\Http\Requests;

use App\Enums\SalesOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** A legacy singular ?status=x caller (dashboard/eligible-list fetches elsewhere in the app) is normalized to the same array shape the new multi-select filter sends, so both validate and filter identically. */
    protected function prepareForValidation(): void
    {
        if ($this->has('status') && ! is_array($this->status)) {
            $this->merge(['status' => array_filter(explode(',', (string) $this->status))]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'array'],
            'status.*' => [Rule::enum(SalesOrderStatus::class)],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            // Not 'boolean': axios serializes JS `true` to the query string "true", which
            // Laravel's boolean rule rejects (it only accepts 1/0/"1"/"0"/true/false). The
            // frontend only ever sends this param to enable the filter, never `=false`.
            'outstanding' => ['sometimes', 'nullable'],
        ];
    }
}
