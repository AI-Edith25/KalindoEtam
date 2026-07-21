<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Trial Balance row — wraps the array shape TrialBalanceService::summarize() returns. */
class TrialBalanceRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->resource['account'];

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type,
            'debit' => $this->resource['debit'],
            'credit' => $this->resource['credit'],
        ];
    }
}
