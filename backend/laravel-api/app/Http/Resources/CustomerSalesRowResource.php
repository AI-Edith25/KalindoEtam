<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Customer Sales row — wraps the stdClass row CustomerSalesRepository's grouped query returns. */
class CustomerSalesRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->customer_id,
            'customer_code' => $this->customer_code,
            'customer_name' => $this->customer_name,
            'branch_name' => $this->branch_name,
            'sales_person_name' => $this->sales_person_name,
            'transaction_count' => (int) $this->transaction_count,
            'qty' => (int) $this->qty,
            'amount' => (float) $this->amount,
            'tax_amount' => (float) $this->tax_amount,
            'amount_incl_tax' => (float) $this->amount + (float) $this->tax_amount,
            'last_transaction_date' => $this->last_transaction_date,
        ];
    }
}
