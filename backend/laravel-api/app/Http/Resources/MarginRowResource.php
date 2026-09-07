<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Margin row — shape depends on which of MarginRepository's 3 grouped queries produced it
 * (item/customer/invoice), detected the same way ProductSalesRowResource branches on presence of
 * group_id. `hpp_missing` flags an aggregated row whose summed cost_amount is exactly 0 (e.g. a
 * Transportation line, or a Goods line the FIFO backfill couldn't resolve) — the frontend renders
 * a warning icon for it; it's already excluded from the page-level Margin Rata-rata KPI (computed
 * at the raw-line grain in MarginRepository::kpis(), not from these aggregated rows).
 */
class MarginRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isCustomer = isset($this->customer_code);
        $isInvoice = isset($this->document_number);

        return [
            'id' => $this->id,
            'item_code' => $isCustomer || $isInvoice ? null : ($this->item_code ?? null),
            'item_name' => $isCustomer || $isInvoice ? null : $this->item_name,
            'customer_code' => $isCustomer ? $this->customer_code : null,
            'customer_name' => $isCustomer || $isInvoice ? $this->customer_name : null,
            'invoice_count' => $isCustomer ? (int) $this->invoice_count : null,
            'date' => $isInvoice ? $this->date : null,
            'document_number' => $isInvoice ? $this->document_number : null,
            'sales_person_name' => $isInvoice ? $this->sales_person_name : null,
            'qty' => $isCustomer || $isInvoice ? null : (float) $this->qty,
            'amount' => (float) $this->amount,
            'cost_amount' => (float) $this->cost_amount,
            'profit' => (float) $this->profit,
            'margin_pct' => (float) $this->margin_pct,
            'hpp_missing' => (float) $this->cost_amount === 0.0,
        ];
    }
}
