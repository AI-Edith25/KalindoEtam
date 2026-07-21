<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item' => new ItemResource($this->whenLoaded('item')),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'transaction_type' => $this->transaction_type,
            'voucher_type' => $this->voucher_type,
            'voucher_id' => $this->voucher_id,
            'reference_no' => $this->reference_no,
            'qty_change' => $this->qty_change,
            'balance_qty' => $this->balance_qty,
            'posting_datetime' => $this->posting_datetime,
            'remarks' => $this->remarks,
        ];
    }
}
