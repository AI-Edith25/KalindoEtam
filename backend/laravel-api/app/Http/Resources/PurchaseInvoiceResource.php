<?php

namespace App\Http\Resources;

use App\Enums\AccountsPayableStatus;
use App\Enums\DocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accountsPayable = $this->relationLoaded('accountsPayable') ? $this->accountsPayable : null;
        $paidAmount = $accountsPayable ? (float) $accountsPayable->paid_amount : 0.0;
        $creditedAmount = $accountsPayable ? (float) $accountsPayable->credited_amount : 0.0;

        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'display_status' => $this->resolveDisplayStatus(),
            'revision' => $this->revision,
            'goods_receipt_id' => $this->goods_receipt_id,
            'goods_receipt' => $this->whenLoaded('goodsReceipt', fn () => $this->goodsReceipt ? [
                'id' => $this->goodsReceipt->id,
                'document_number' => $this->goodsReceipt->document_number,
                'warehouse' => $this->goodsReceipt->relationLoaded('warehouse') ? new WarehouseResource($this->goodsReceipt->warehouse) : null,
            ] : null),
            'goods_receipts' => $this->whenLoaded('goodsReceipts', fn () => $this->goodsReceipts->map(fn ($goodsReceipt) => [
                'id' => $goodsReceipt->id,
                'document_number' => $goodsReceipt->document_number,
            ])),
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_orders' => $this->whenLoaded('purchaseOrders', fn () => $this->purchaseOrders->map(fn ($purchaseOrder) => [
                'id' => $purchaseOrder->id,
                'document_number' => $purchaseOrder->document_number,
            ])),
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'invoice_date' => $this->invoice_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'paid_amount' => $paidAmount,
            'outstanding_amount' => (float) $this->grand_total - $paidAmount,
            'credited_amount' => $creditedAmount,
            'returnable_amount' => (float) $this->grand_total - $creditedAmount,
            'reference_number' => $this->reference_number,
            'remarks' => $this->remarks,
            'items' => PurchaseInvoiceItemResource::collection($this->whenLoaded('items')),
            'purchase_return_history' => $this->relationLoaded('purchaseReturns')
                ? $this->purchaseReturns->map(fn ($purchaseReturn) => [
                    'id' => $purchaseReturn->id,
                    'document_number' => $purchaseReturn->document_number,
                    'return_date' => $purchaseReturn->return_date?->format('Y-m-d'),
                    'reason' => $purchaseReturn->reason,
                    'total_amount' => $purchaseReturn->total_amount,
                    'status' => $purchaseReturn->status,
                    'is_reversed' => $purchaseReturn->is_reversed,
                ])
                : [],
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Draft/Cancelled are document-lifecycle states, not payment states —
     * kept as-is. Once submitted, the payment status is only
     * Unpaid/Partial/Paid, derived from AccountsPayable, never stored.
     * Mirrors InvoiceResource::resolveDisplayStatus().
     */
    protected function resolveDisplayStatus(): string
    {
        if ($this->status === DocumentStatus::CANCELLED) {
            return 'cancelled';
        }

        if ($this->status === DocumentStatus::DRAFT) {
            return 'draft';
        }

        $apStatus = $this->relationLoaded('accountsPayable') ? $this->accountsPayable?->status : null;

        return match ($apStatus) {
            AccountsPayableStatus::PARTIALLY_PAID => 'partial',
            AccountsPayableStatus::PAID => 'paid',
            default => 'unpaid',
        };
    }
}
