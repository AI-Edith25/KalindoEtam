<?php

namespace App\Http\Resources;

use App\Enums\TaxCalculationMode;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'sales_order_id' => $this->sales_order_id,
            'sales_order' => new SalesOrderResource($this->whenLoaded('salesOrder')),
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'terms_of_payment_id' => $this->terms_of_payment_id,
            'terms_of_payment' => new TermsOfPaymentResource($this->whenLoaded('termsOfPayment')),
            'remarks' => $this->remarks,
            'items' => DeliveryItemResource::collection($this->whenLoaded('items')),
            'amount' => $this->whenLoaded('items', fn () => $this->items->sum('amount')),
            // Read-only, inherited from the Sales Order's tax code (never an independent
            // choice on the Delivery — B1 of the workflow spec) and recomputed against this
            // Delivery's own item amounts, so a partial shipment's tax is correct on its own.
            'tax_id' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder?->tax_id),
            'tax' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder?->relationLoaded('tax') && $this->salesOrder->tax
                ? new TaxResource($this->salesOrder->tax)
                : null),
            'tax_amount' => $this->when($this->relationLoaded('items') && $this->relationLoaded('salesOrder'), function () {
                $tax = $this->salesOrder?->relationLoaded('tax') ? $this->salesOrder->tax : null;

                return app(TaxService::class)->calculate((float) $this->items->sum('amount'), $tax, TaxCalculationMode::EXCLUSIVE)['tax_amount'];
            }),
            'is_invoiced' => $this->whenLoaded('invoices', fn () => $this->invoices->isNotEmpty()),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
