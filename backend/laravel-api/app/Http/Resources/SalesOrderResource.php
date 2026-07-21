<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'order_date' => $this->order_date?->format('Y-m-d'),
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'total_amount' => $this->total_amount,
            'remarks' => $this->remarks,
            'items' => SalesOrderItemResource::collection($this->whenLoaded('items')),
            'is_fully_delivered' => $this->whenLoaded('items', fn () => $this->items->every(fn ($item) => $item->delivered_qty >= $item->qty)),
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
