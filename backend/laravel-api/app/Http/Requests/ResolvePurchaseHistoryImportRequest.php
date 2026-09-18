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
            'resolutions' => ['required', 'array'],
            'resolutions.*.category' => ['required', Rule::in(['supplier', 'item', 'duplicate'])],
            'resolutions.*.value' => ['required', 'string'],
            'resolutions.*.action' => ['required', Rule::in(['create', 'map', 'skip', 'proceed'])],
            'resolutions.*.target_id' => ['nullable', 'uuid'],
        ];
    }
}
