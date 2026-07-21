<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // One extra query per line — acceptable at this app's scale (an
        // Invoice rarely has more than a handful of lines), same
        // pragmatism as SalesOrderResource's is_fully_delivered check.
        $creditedTotals = $this->creditNoteItems()
            ->whereHas('creditNote', fn ($query) => $query->where('status', 'submitted')->where('is_reversed', false))
            ->selectRaw('COALESCE(SUM(qty_credited), 0) as qty, COALESCE(SUM(amount), 0) as amount')
            ->first();

        return [
            'id' => $this->id,
            'delivery_item_id' => $this->delivery_item_id,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'uom' => $this->uom,
            'rate' => $this->rate,
            'qty' => $this->qty,
            'amount' => $this->amount,
            'credited_qty' => (int) $creditedTotals->qty,
            'credited_amount' => (float) $creditedTotals->amount,
            'creditable_qty' => (int) $this->qty - (int) $creditedTotals->qty,
            'creditable_amount' => (float) $this->amount - (float) $creditedTotals->amount,
        ];
    }
}
