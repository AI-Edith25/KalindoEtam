<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'file' => ['required', 'file', 'max:10240'],
        ];
    }
}
