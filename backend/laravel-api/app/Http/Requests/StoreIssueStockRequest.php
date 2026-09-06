<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIssueStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'issue_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            // Whole-number-vs-decimal enforcement happens in IssueStockService via
            // QtyCategoryValidator. Unit Cost is never accepted from the client — it's
            // computed by FifoLayerService::consume() at submit() time.
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
