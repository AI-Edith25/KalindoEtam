<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Product Sales row — wraps the stdClass row ProductSalesRepository's grouped query returns.
 * Shape differs slightly by $this->group_id presence (Item Group view) vs item view; % contribution
 * isn't computed here — the frontend already has both row.amount and meta.kpis.total_revenue in the
 * same response, so it's a one-line client-side division rather than a second server round trip.
 */
class ProductSalesRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isGrouped = isset($this->group_id);

        return [
            'id' => $isGrouped ? $this->group_id : $this->item_id,
            'is_group' => $isGrouped,
            'item_code' => $isGrouped ? null : $this->item_code,
            'item_name' => $isGrouped ? $this->group_name : $this->item_name,
            'item_group_name' => $isGrouped ? null : ($this->group_name ?? 'Unassigned'),
            'uom_name' => $isGrouped ? null : $this->uom_name,
            'sku_count' => $isGrouped ? (int) $this->sku_count : null,
            'qty' => (int) $this->qty,
            'amount' => (float) $this->amount,
            'tax_amount' => (float) $this->tax_amount,
            'amount_incl_tax' => (float) $this->amount + (float) $this->tax_amount,
        ];
    }
}
