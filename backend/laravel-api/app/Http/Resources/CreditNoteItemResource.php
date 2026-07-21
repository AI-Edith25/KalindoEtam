<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditNoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_item_id' => $this->invoice_item_id,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'uom' => $this->uom,
            'qty_credited' => $this->qty_credited,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'restock' => $this->restock,
            // No StockLedger movement is ever posted for this yet — see
            // CreditNote's docblock. Frontend shows this as a plain label.
            'inventory_impact' => $this->restock ? 'Pending Inventory Return Module' : null,
        ];
    }
}
