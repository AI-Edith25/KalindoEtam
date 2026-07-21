<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chart_of_account_id' => $this->chart_of_account_id,
            'chart_of_account' => $this->whenLoaded('chartOfAccount', fn () => [
                'id' => $this->chartOfAccount->id,
                'code' => $this->chartOfAccount->code,
                'name' => $this->chartOfAccount->name,
            ]),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
        ];
    }
}
