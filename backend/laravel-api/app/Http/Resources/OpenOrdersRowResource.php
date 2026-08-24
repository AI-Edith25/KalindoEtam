<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One Open Orders row — wraps the stdClass row OpenOrdersRepository's query returns. Delivery/
 * Invoice status are two separate derived labels (not one combined enum — see
 * OpenOrdersRepository's own docblock and the Sales Report rework plan's "Assumptions" §5), and
 * age/overdue are computed here in PHP rather than in SQL for cross-database portability.
 */
class OpenOrdersRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $qtyOrdered = (int) $this->qty_ordered;
        $qtyDelivered = (int) $this->qty_delivered;
        $qtyInvoiced = (int) $this->qty_invoiced;
        $expectedDeliveryDate = $this->expected_delivery_date;
        $ageInDays = (int) Carbon::parse($this->order_date)->diffInDays(Carbon::today());
        $overdue = $expectedDeliveryDate !== null && Carbon::parse($expectedDeliveryDate)->isPast();

        return [
            'id' => $this->id,
            'sales_order_id' => $this->sales_order_id,
            'document_number' => $this->document_number,
            'order_date' => $this->order_date,
            'expected_delivery_date' => $expectedDeliveryDate,
            'customer_name' => $this->customer_name,
            'sales_person_name' => $this->sales_person_name ?? 'Unassigned',
            'branch_name' => $this->branch_name,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'qty_ordered' => $qtyOrdered,
            'qty_delivered' => $qtyDelivered,
            'qty_invoiced' => $qtyInvoiced,
            'qty_outstanding' => (int) $this->qty_outstanding,
            'outstanding_value' => (float) $this->outstanding_value,
            'delivery_status' => $qtyDelivered <= 0 ? 'not_delivered' : ($qtyDelivered < $qtyOrdered ? 'partially_delivered' : 'fully_delivered'),
            'invoice_status' => $qtyInvoiced <= 0 ? 'not_invoiced' : ($qtyInvoiced < $qtyOrdered ? 'partially_invoiced' : 'fully_invoiced'),
            'age_in_days' => $ageInDays,
            'is_overdue' => $overdue,
        ];
    }
}
