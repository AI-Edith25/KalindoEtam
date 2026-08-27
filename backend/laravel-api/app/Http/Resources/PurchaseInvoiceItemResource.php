<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $returnedTotals = $this->purchaseReturnItems()
            ->whereHas('purchaseReturn', fn ($query) => $query->where('status', 'submitted')->where('is_reversed', false))
            ->selectRaw('COALESCE(SUM(qty_returned), 0) as qty, COALESCE(SUM(amount), 0) as amount')
            ->first();

        return [
            'id' => $this->id,
            'goods_receipt_item_id' => $this->goods_receipt_item_id,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'uom' => $this->uom,
            'item_qty_category' => $this->whenLoaded('item', fn () => $this->item->qty_category),
            'rate' => $this->rate,
            'qty' => $this->qty,
            'amount' => $this->amount,
            'returned_qty' => (float) $returnedTotals->qty,
            'returned_amount' => (float) $returnedTotals->amount,
            'returnable_qty' => (float) $this->qty - (float) $returnedTotals->qty,
            'returnable_amount' => (float) $this->amount - (float) $returnedTotals->amount,
        ];
    }
}
