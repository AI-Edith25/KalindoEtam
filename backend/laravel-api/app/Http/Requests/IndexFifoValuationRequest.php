<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexFifoValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            // Not 'boolean' — axios serializes a JS boolean query param as the literal string
            // "true"/"false", which Laravel's boolean rule rejects (only 1/0/"1"/"0" pass).
            // FifoLayerRepository treats any non-empty, non-"false"/"0" value as true.
            'hide_exhausted' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
