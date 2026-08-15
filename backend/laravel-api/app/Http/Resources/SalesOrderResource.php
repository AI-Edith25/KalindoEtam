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
            'sales_person_id' => $this->sales_person_id,
            'sales_person' => new SalesPersonResource($this->whenLoaded('salesPerson')),
            'branch_id' => $this->branch_id,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'order_date' => $this->order_date?->format('Y-m-d'),
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'total_amount' => $this->total_amount,
            'tax_id' => $this->tax_id,
            'tax' => $this->whenLoaded('tax', fn () => $this->tax ? new TaxResource($this->tax) : null),
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'remarks' => $this->remarks,
            'attention' => $this->attention,
            'tel' => $this->tel,
            'fax' => $this->fax,
            'reference' => $this->reference,
            'terms_of_payment_id' => $this->terms_of_payment_id,
            'terms_of_payment' => new TermsOfPaymentResource($this->whenLoaded('termsOfPayment')),
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
