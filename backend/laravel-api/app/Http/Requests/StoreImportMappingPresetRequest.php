<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** mapping/clean_settings are copied from the batch they're saved from — already validated when the batch's mapping step ran, so only the preset's own name needs checking here. */
class StoreImportMappingPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('import_mapping_presets')->where('module', $this->route('batch')->module),
            ],
        ];
    }
}
