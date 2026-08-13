<?php

namespace App\Http\Requests;

use App\Models\ReceiptEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attachable_type' => ['required', 'string', 'max:255'],
            'attachable_id' => ['required', 'uuid'],
            // D1 (UAT review 2026-08-12): Incoming Payment proof-of-transfer only —
            // every other attachable_type keeps the no-whitelist behavior above unchanged.
            'file' => [
                'required',
                'file',
                'max:10240',
                Rule::when($this->input('attachable_type') === ReceiptEntry::class, ['mimes:jpg,jpeg,png,pdf']),
            ],
        ];
    }
}
