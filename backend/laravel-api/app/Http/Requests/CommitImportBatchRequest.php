<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommitImportBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'write_mode' => ['required', 'string', 'in:insert_only,update_only,upsert'],
            'commit_mode' => ['required', 'string', 'in:skip_invalid,all_or_nothing'],
        ];
    }
}
