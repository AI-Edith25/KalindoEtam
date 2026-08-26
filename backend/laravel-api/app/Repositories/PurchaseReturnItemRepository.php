<?php

namespace App\Repositories;

use App\Models\PurchaseReturnItem;

class PurchaseReturnItemRepository extends BaseRepository
{
    public function __construct(PurchaseReturnItem $model)
    {
        parent::__construct($model);
    }

    /**
     * Sum of qty_returned/amount already returned against a single
     * PurchaseInvoiceItem, across every non-reversed Purchase Return line
     * — the guard that stops a line from ever being over-returned across
     * multiple separate Returns. Mirrors
     * CreditNoteItemRepository::creditedTotalsForInvoiceItem().
     */
    public function returnedTotalsForPurchaseInvoiceItem(string $purchaseInvoiceItemId): array
    {
        $row = $this->model->query()
            ->where('purchase_invoice_item_id', $purchaseInvoiceItemId)
            ->whereHas('purchaseReturn', fn ($q) => $q->where('is_reversed', false)->where('status', 'submitted'))
            ->selectRaw('COALESCE(SUM(qty_returned), 0) as qty, COALESCE(SUM(amount), 0) as amount')
            ->first();

        return ['qty' => (int) $row->qty, 'amount' => (float) $row->amount];
    }
}
