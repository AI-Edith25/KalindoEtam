<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Same filter shape as IndexInvoiceRequest (search/status/date_from/date_to) plus the export-only mode/format/ids. */
class ExportSalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['summary', 'detail'])],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
            // Checked rows on the Invoices list — when present, overrides search/status/date_from/date_to entirely (see InvoiceRepository::searchAll()).
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid', 'exists:invoices,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
