<?php

namespace App\Repositories;

use App\Models\CreditNoteItem;

class CreditNoteItemRepository extends BaseRepository
{
    public function __construct(CreditNoteItem $model)
    {
        parent::__construct($model);
    }

    /**
     * Sum of qty_credited/amount already credited against a single
     * InvoiceItem, across every non-reversed Credit Note line — the guard
     * that stops a line from ever being over-credited across multiple
     * separate Credit Notes.
     */
    public function creditedTotalsForInvoiceItem(string $invoiceItemId): array
    {
        $row = $this->model->query()
            ->where('invoice_item_id', $invoiceItemId)
            ->whereHas('creditNote', fn ($q) => $q->where('is_reversed', false)->where('status', 'submitted'))
            ->selectRaw('COALESCE(SUM(qty_credited), 0) as qty, COALESCE(SUM(amount), 0) as amount')
            ->first();

        return ['qty' => (int) $row->qty, 'amount' => (float) $row->amount];
    }
}
