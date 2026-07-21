<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'order_date' => $this->order_date?->format('Y-m-d'),
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'total_amount' => $this->total_amount,
            'tax_id' => $this->tax_id,
            'tax' => $this->whenLoaded('tax', fn () => $this->tax ? new TaxResource($this->tax) : null),
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'remarks' => $this->remarks,
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'is_fully_received' => $this->whenLoaded('items', fn () => $this->items->every(fn ($item) => $item->received_qty >= $item->qty)),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'requires_approval' => $this->requiresApproval(),
            'latest_approval' => $this->whenLoaded('approvalFlows', fn () => $this->approvalFlows->sortByDesc('step')->first()
                ? new ApprovalFlowResource($this->approvalFlows->sortByDesc('step')->first())
                : null),
        ];
    }
}
