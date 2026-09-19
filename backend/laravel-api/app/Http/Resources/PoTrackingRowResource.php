<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PO Tracking row — wraps the stdClass row PoTrackingRepository's filtered/aggregated query
 * returns. Every qty-based field is null for a row fabricated by the Purchase History import
 * (import_source_type set) — see PoTrackingRepository::filteredQuery() for why; the frontend
 * renders null as "-" rather than a misleading 0/1.
 */
class PoTrackingRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_date' => $this->order_date,
            'document_number' => $this->document_number,
            'supplier_name' => $this->supplier_name,
            'total_amount' => (float) $this->total_amount,
            'ordered_qty' => $this->ordered_qty !== null ? (float) $this->ordered_qty : null,
            'received_qty' => $this->received_qty !== null ? (float) $this->received_qty : null,
            'remaining_qty' => $this->remaining_qty !== null ? (float) $this->remaining_qty : null,
            'fulfillment_pct' => $this->fulfillment_pct !== null ? (float) $this->fulfillment_pct : null,
            'receiving_status' => $this->receiving_status,
            'is_overdue' => (bool) $this->is_overdue,
            'import_source_type' => $this->import_source_type,
            'amount_billed' => $this->amount_billed !== null ? (float) $this->amount_billed : null,
            'outstanding_grn_value' => $this->outstanding_grn_value !== null ? (float) $this->outstanding_grn_value : null,
            'outstanding_po_value' => $this->outstanding_po_value !== null ? (float) $this->outstanding_po_value : null,
            'fulfillment_pct_value' => $this->fulfillment_pct_value !== null ? (float) $this->fulfillment_pct_value : null,
        ];
    }
}
