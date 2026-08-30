<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Sales Listing row — wraps the stdClass row SalesListingRepository's union query returns.
 * A Credit Note row's amount/discount/tax/amount_incl_tax are already negative (see
 * SalesListingRepository::creditNoteQuery()) so a client-side sum nets correctly without any
 * type-branching. payment_status/outstanding_ar are null for Credit Note rows — a credit note
 * doesn't carry its own AR status, it adjusts the original invoice's.
 */
class SalesListingRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'document_number' => $this->document_number,
            'date' => $this->date,
            'reference_so_number' => $this->reference_so,
            'reference_do_number' => $this->reference_do,
            'customer_code' => $this->customer_code,
            'customer_name' => $this->customer_name,
            'sales_person_name' => $this->sales_person_name ?? 'Unassigned',
            'branch_name' => $this->branch_name,
            'amount' => (float) $this->amount,
            'discount' => (float) $this->discount,
            'tax' => (float) $this->tax,
            'amount_incl_tax' => (float) $this->amount_incl_tax,
            'payment_status' => $this->payment_status,
            'outstanding_ar' => $this->outstanding_ar !== null ? (float) $this->outstanding_ar : null,
        ];
    }
}
