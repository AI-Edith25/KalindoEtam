<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One AR Aging grid row — wraps the array shape AccountsReceivableService::summarizeAging() returns. */
class AccountsReceivableAgingRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $customer = $this->resource['customer'];

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'bucket_0_30' => $this->resource['bucket_0_30'],
            'bucket_31_60' => $this->resource['bucket_31_60'],
            'bucket_61_90' => $this->resource['bucket_61_90'],
            'bucket_over_90' => $this->resource['bucket_over_90'],
            'total_outstanding' => $this->resource['total_outstanding'],
        ];
    }
}
