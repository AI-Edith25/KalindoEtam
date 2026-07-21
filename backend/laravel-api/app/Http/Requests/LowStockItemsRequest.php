<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LowStockItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'threshold' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function resolvedThreshold(): int
    {
        return (int) ($this->validated('threshold') ?? 10);
    }
}
