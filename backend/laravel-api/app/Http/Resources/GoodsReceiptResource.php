<?php

namespace App\Http\Resources;

use App\Enums\DocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'receipt_date' => $this->receipt_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'remarks' => $this->remarks,
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            // Mirrors DeliveryResource::is_invoiced — only meaningful once submitted (stock has
            // moved by then), used by Purchase Invoice's "eligible Goods Receipts" picker.
            'is_invoiced' => $this->whenLoaded('purchaseInvoices', fn () => $this->status === DocumentStatus::SUBMITTED ? $this->purchaseInvoices->isNotEmpty() : null),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
