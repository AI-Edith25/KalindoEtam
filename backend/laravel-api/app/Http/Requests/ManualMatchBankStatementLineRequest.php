<?php

namespace App\Http\Requests;

use App\Models\PaymentEntry;
use App\Models\ReceiptEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualMatchBankStatementLineRequest extends FormRequest
{
    private const DOCUMENT_MODELS = [
        'payment_entry' => PaymentEntry::class,
        'receipt_entry' => ReceiptEntry::class,
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $modelClass = self::DOCUMENT_MODELS[$this->input('document_type')] ?? PaymentEntry::class;

        return [
            'document_type' => ['required', Rule::in(array_keys(self::DOCUMENT_MODELS))],
            'document_id' => ['required', 'uuid', Rule::exists((new $modelClass())->getTable(), 'id')],
        ];
    }
}
