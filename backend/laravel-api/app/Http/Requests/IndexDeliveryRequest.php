<?php

namespace App\Http\Requests;

use App\Enums\DeliveryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Delivery overrides Documentable's default Draft/Submitted/Cancelled lifecycle with
            // its own Pending/Complete enum (see Delivery::initialStatus()/submittedStatus()) —
            // must validate against that, not the generic DocumentStatus (whose values don't
            // include "complete"/"pending" at all, so every status-filtered request 422'd:
            // New Invoice's eligible-deliveries fetch, this list's own Status filter, and the
            // Outstanding toggle all send status=complete/pending).
            'status' => ['sometimes', 'nullable', Rule::enum(DeliveryStatus::class)],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
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
