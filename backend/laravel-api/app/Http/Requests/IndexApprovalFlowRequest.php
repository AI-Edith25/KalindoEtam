<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Mirrors IndexDocumentTimelineRequest exactly — same "read by type+id" shape. */
class IndexApprovalFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approvable_type' => ['required', 'string', 'max:255'],
            'approvable_id' => ['required', 'uuid'],
        ];
    }
}
