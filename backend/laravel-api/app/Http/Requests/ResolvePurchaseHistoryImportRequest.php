<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolvePurchaseHistoryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'present', not 'required' — this endpoint is now the universal "confirm and queue"
            // step (see PurchaseHistoryImportController::store()), called even when preflight()
            // found nothing to resolve, in which case the frontend legitimately sends an empty
            // array. 'required' rejects an empty array outright (count() < 1), which would 422 the
            // no-resolution-needed path.
            'resolutions' => ['present', 'array'],
            'resolutions.*.category' => ['required', Rule::in(['supplier', 'item', 'duplicate'])],
            'resolutions.*.value' => ['required', 'string'],
            'resolutions.*.action' => ['required', Rule::in(['create', 'map', 'skip', 'proceed'])],
            'resolutions.*.target_id' => ['nullable', 'uuid'],
        ];
    }
}
