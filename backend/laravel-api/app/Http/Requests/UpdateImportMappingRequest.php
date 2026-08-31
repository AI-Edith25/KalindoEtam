<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // mapping: {fileHeader: systemFieldName|null}
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
            'clean_settings' => ['sometimes', 'array'],
            'clean_settings.*' => ['string', 'in:dot_decimal,dot_thousands'],
        ];
    }
}
