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
            // field_defaults: {systemFieldName: constantValue} — applied to every row for a
            // required field with no source column (e.g. Group when the file has none).
            'field_defaults' => ['sometimes', 'array'],
            'field_defaults.*' => ['nullable', 'string'],
        ];
    }
}
