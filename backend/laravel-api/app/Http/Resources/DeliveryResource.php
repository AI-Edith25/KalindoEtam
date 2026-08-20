<?php

namespace App\Http\Resources;

use App\Enums\DeliveryStatus;
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
            'fleet' => $this->fleet,
            'driver' => $this->driver,
            'items' => DeliveryItemResource::collection($this->whenLoaded('items')),
            'amount' => $this->whenLoaded('items', fn () => $this->items->sum('amount')),
            // Tax is per-line now (each item's own tax_id/tax_amount, already resolved when
            // the Sales Order line/Delivery line was created) — tax_amount below is always
            // the accurate sum. tax_id/tax only resolve to a single value when every line
            // happens to share the same Tax; a genuinely mixed-tax shipment shows "—" here
            // (the frontend already renders a null tax as that), same as no tax at all.
            'tax_id' => $this->whenLoaded('items', function () {
                $taxIds = $this->items->pluck('tax_id')->unique();

                return $taxIds->count() === 1 ? $taxIds->first() : null;
            }),
            'tax' => $this->whenLoaded('items', function () {
                $taxes = $this->items->pluck('tax')->filter()->unique('id');

                return $taxes->count() === 1 ? new TaxResource($taxes->first()) : null;
            }),
            'tax_amount' => $this->whenLoaded('items', fn () => round((float) $this->items->sum('tax_amount'), 2)),
            // null (not false) while still Pending — "not invoiced" implies "eligible to invoice
            // right now," which isn't true until the Delivery is Complete. The frontend already
            // renders null as "—" rather than a "Not Invoiced" badge (DeliveryListPage.tsx).
            'is_invoiced' => $this->whenLoaded('invoices', fn () => $this->status === DeliveryStatus::COMPLETE ? $this->invoices->isNotEmpty() : null),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
