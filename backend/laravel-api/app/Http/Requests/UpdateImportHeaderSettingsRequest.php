<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateImportHeaderSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'header_row' => ['required', 'integer', 'min:1'],
            'data_start_row' => ['required', 'integer', 'min:1', 'gt:header_row'],
        ];
    }
}
