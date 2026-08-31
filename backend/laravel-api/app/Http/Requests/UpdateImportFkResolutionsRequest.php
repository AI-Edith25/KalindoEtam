<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateImportFkResolutionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // resolutions: {fieldName: {rawValue: {action: map|create|skip, target_id: uuid|null}}}
            'resolutions' => ['required', 'array'],
            'resolutions.*' => ['array'],
            'resolutions.*.*.action' => ['required', 'string', 'in:map,create,skip'],
            'resolutions.*.*.target_id' => ['nullable', 'uuid'],
        ];
    }
}
