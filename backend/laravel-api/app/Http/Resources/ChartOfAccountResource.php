<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChartOfAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->account_type,
            'is_active' => $this->is_active,
            'is_cash_bank' => $this->is_cash_bank,
            'cash_bank_category' => $this->cash_bank_category,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
