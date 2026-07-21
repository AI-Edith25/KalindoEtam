<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Ledger List row — wraps the array shape GeneralLedgerService::listAccounts() returns. */
class LedgerAccountSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->resource['account'];

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type,
            'is_active' => $account->is_active,
            'opening_balance' => $this->resource['opening_balance'],
            'debit' => $this->resource['debit'],
            'credit' => $this->resource['credit'],
            'ending_balance' => $this->resource['ending_balance'],
        ];
    }
}
