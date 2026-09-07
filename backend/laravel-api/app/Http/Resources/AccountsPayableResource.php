<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountsPayableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('purchaseInvoice', fn () => $this->purchaseInvoice ? [
                'id' => $this->purchaseInvoice->id,
                'document_number' => $this->purchaseInvoice->document_number,
                'invoice_date' => $this->purchaseInvoice->invoice_date?->format('Y-m-d'),
                'status' => $this->purchaseInvoice->status,
            ] : null),
            'purchase_order_id' => $this->purchase_order_id,
            'goods_receipt_id' => $this->goods_receipt_id,
            // Every AccountsPayable row's Goods Receipt is NOT NULL and always has a NOT NULL
            // warehouse_id — one hop, no fallback chain needed (unlike AR's branch_id, which
            // needs one because Transportation invoices have no Sales Order to inherit from).
            'warehouse_name' => $this->whenLoaded('goodsReceipt', fn () => $this->goodsReceipt?->warehouse?->name),
            // Umur: days elapsed since invoice_date, computed PHP-side (not whereRaw/DATEDIFF) —
            // same MySQL-vs-SQLite portability rule as AccountsReceivableResource::age_in_days.
            'age_in_days' => $this->whenLoaded('purchaseInvoice', fn () => $this->purchaseInvoice?->invoice_date
                ? (int) $this->purchaseInvoice->invoice_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), true)
                : null),
            'reference_number' => $this->reference_number,
            'amount' => $this->amount,
            // Ground truth is a live SUM over payment_entry_allocations (paid_amount_computed,
            // attached by AccountsPayableRepository's allocation-sum join / paidAmountFor()),
            // never the accounts_payables.paid_amount cache column — per the report's own
            // requirement that "Sudah Dibayar"/"Sisa Hutang" reflect actual allocations.
            'paid_amount' => (float) ($this->paid_amount_computed ?? 0),
            'outstanding_amount' => (float) $this->amount - (float) ($this->paid_amount_computed ?? 0),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
