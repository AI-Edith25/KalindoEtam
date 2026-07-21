<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Cash Flow adjustment/activity line — wraps the array shape CashFlowService::summarize() returns per account. */
class CashFlowLineResource extends JsonResource
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
