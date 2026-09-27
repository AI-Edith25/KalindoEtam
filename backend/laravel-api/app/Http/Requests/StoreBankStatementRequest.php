<?php

namespace App\Http\Requests;

use App\Services\BankStatement\BankStatementParserRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Omitted -> auto-detect from the file's structure (see BankStatementService::upload()).
            'format_template' => ['nullable', 'string', Rule::in(app(BankStatementParserRegistry::class)->codes())],
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt'],
        ];
    }
}
