<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One By Item row — wraps the stdClass row PurchaseByItemRepository's grouped query returns. Price fields are null when the item had no in-period Goods Receipt (only a Return netted against a prior period). */
class PurchaseByItemRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'uom' => $this->uom,
            'qty' => (float) $this->qty,
            'amount' => (float) $this->amount,
            'avg_price' => $this->avg_price !== null ? (float) $this->avg_price : null,
            'last_price' => $this->last_price !== null ? (float) $this->last_price : null,
            'lowest_price' => $this->lowest_price !== null ? (float) $this->lowest_price : null,
            'highest_price' => $this->highest_price !== null ? (float) $this->highest_price : null,
        ];
    }
}
