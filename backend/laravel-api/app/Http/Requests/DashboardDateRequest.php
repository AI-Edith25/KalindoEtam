<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date'],
        ];
    }

    public function resolvedDate(): string
    {
        return $this->validated('date') ?? now()->toDateString();
    }
}
