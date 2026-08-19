<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountsReceivableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'document_number' => $this->invoice->document_number,
                'invoice_date' => $this->invoice->invoice_date?->format('Y-m-d'),
                'status' => $this->invoice->status,
                'reference_1' => $this->invoice->reference_1,
                'reference_2' => $this->invoice->reference_2,
                // Laporan Penagihan Harian's Reference column (2026-08-19) shows the same delivery
                // document numbers as Sales > Invoices' own "Reference" column — not reference_1
                // (an SO reference number, a different concept despite the shared column name).
                'deliveries' => $this->invoice->relationLoaded('deliveries')
                    ? $this->invoice->deliveries->pluck('document_number')->filter()->values()
                    : [],
            ] : null),
            'sales_order_id' => $this->sales_order_id,
            'delivery_id' => $this->delivery_id,
            'delivery' => $this->whenLoaded('delivery', fn () => $this->delivery ? [
                'id' => $this->delivery->id,
                'document_number' => $this->delivery->document_number,
            ] : null),
            // Masa: the Invoice's own terms_of_payment_id (already the authoritative value after
            // the Customer->Delivery->Invoice inheritance chain) — null if unset upstream.
            'terms_of_payment_days' => $this->whenLoaded('invoice', fn () => $this->invoice?->termsOfPayment?->days),
            // Umur: days elapsed since invoice_date, computed PHP-side (not whereRaw/DATEDIFF) —
            // same MySQL-vs-SQLite portability rule as this repository's aging_bucket filter.
            'age_in_days' => $this->invoice?->invoice_date ? (int) $this->invoice->invoice_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), true) : null,
            // Nama Sales: AccountsReceivable -> SalesOrder -> SalesPerson — null if the Sales
            // Order predates Sales Person assignment or none was set.
            'sales_person_name' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder?->salesPerson?->name),
            'reference_number' => $this->reference_number,
            'amount' => $this->amount,
            'paid_amount' => $this->paid_amount,
            'outstanding_amount' => $this->amount - $this->paid_amount,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
