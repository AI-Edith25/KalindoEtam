<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Profit & Loss section line — wraps the array shape ProfitLossService::summarize() returns per account. */
class ProfitLossLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->resource['account'];

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'amount' => $this->resource['amount'],
        ];
    }
}
