<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveSalesInvoiceHistoryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'present', not 'required' — the universal confirm-and-queue step, called even when
            // preflight() found nothing to resolve (see StorePurchaseHistoryImportRequest's own
            // identical convention).
            'resolutions' => ['present', 'array'],
            'resolutions.*.category' => ['required', Rule::in(['customer', 'item', 'duplicate'])],
            'resolutions.*.value' => ['required', 'string'],
            'resolutions.*.action' => ['required', Rule::in(['map', 'skip', 'proceed'])],
            'resolutions.*.target_id' => ['nullable', 'uuid'],
        ];
    }
}
