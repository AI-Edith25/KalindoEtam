<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One By Supplier row — wraps the stdClass row PurchaseBySupplierRepository's grouped query returns. "% dari Total" isn't computed here — the frontend already has both row.amount and meta.kpis.total_purchases, same one-line-division-on-the-client approach ProductSalesRowResource uses. */
class PurchaseBySupplierRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_code' => $this->supplier_code,
            'supplier_name' => $this->supplier_name,
            'receipt_count' => (int) $this->receipt_count,
            'qty' => (float) $this->qty,
            'amount' => (float) $this->amount,
        ];
    }
}
