<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerOutstandingSnapshot;
use App\Models\SalesListingSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads sales_listing_snapshot_lines across every stacked period -- feeds both the Sales
 * Listing tab (1:1) and the Customer Sales tab (grouped by customer_code), since both come
 * from the same File A rows. No pagination at the SQL level: an archive is a handful of
 * imported periods, not an unbounded live table, so date/customer filtering happens in SQL but
 * type/payment-status/search filtering, the AR join, and pagination all happen in PHP over the
 * already-narrow result set -- simpler than pushing the AR join into SQL for a dataset this size.
 */
class SalesListingArchiveService
{
    public function hasSnapshot(): bool
    {
        return SalesListingSnapshot::query()->exists();
    }

    /** @return ?array{period_start: string, period_end: string} min/max across every stacked period, for the banner. */
    public function combinedPeriod(): ?array
    {
        $agg = SalesListingSnapshot::query()->selectRaw('MIN(period_start) as start, MAX(period_end) as end')->first();

        return $agg && $agg->start ? ['period_start' => $agg->start, 'period_end' => $agg->end] : null;
    }

    /** @return array{data: array, total: int, kpis: array} */
    public function salesListing(array $filters, int $page, int $perPage): array
    {
        if (! empty($filters['sales_person_id']) || ! empty($filters['branch_id'])) {
            return ['data' => [], 'total' => 0, 'kpis' => $this->emptyListingKpis()];
        }

        $lines = $this->baseLines($filters);
        if ($lines === null) {
            return ['data' => [], 'total' => 0, 'kpis' => $this->emptyListingKpis()];
        }

        $arMap = $this->arUnpaidMap();
        $rows = $lines->map(fn ($line) => $this->mapListingRow($line, $arMap));

        if (! empty($filters['type'])) {
            $rows = $rows->filter(fn ($row) => $row['type'] === $filters['type']);
        }
        if (! empty($filters['payment_status'])) {
            $rows = $rows->filter(fn ($row) => $row['payment_status'] === $filters['payment_status']);
        }
        if (! empty($filters['search'])) {
            $needle = mb_strtolower($filters['search']);
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower((string) $row['document_number']), $needle)
                || str_contains(mb_strtolower($row['customer_name']), $needle));
        }

        $rows = $rows->sortByDesc('date')->values();

        $kpis = [
            'net_sales' => round((float) $rows->sum('amount'), 2),
            'total_tax' => round((float) $rows->sum('tax'), 2),
            'gross' => round((float) $rows->sum('amount_incl_tax'), 2),
            'invoice_count' => $rows->where('type', 'invoice')->count(),
            'paid_value' => round((float) $rows->where('type', 'invoice')->where('payment_status', 'paid')->sum('amount_incl_tax'), 2),
            'unpaid_value' => round((float) $rows->where('type', 'invoice')->where('payment_status', '!=', 'paid')->sum('amount_incl_tax'), 2),
        ];

        return [
            'data' => $rows->forPage($page, $perPage)->values()->all(),
            'total' => $rows->count(),
            'kpis' => $kpis,
        ];
    }

    /** @return array{data: array, total: int, kpis: array} */
    public function customerSales(array $filters, int $page, int $perPage): array
    {
        if (! empty($filters['item_id']) || ! empty($filters['item_group_id']) || ! empty($filters['sales_person_id'])
            || ! empty($filters['branch_id']) || ! empty($filters['status'])) {
            return ['data' => [], 'total' => 0, 'kpis' => $this->emptyCustomerKpis()];
        }

        $lines = $this->baseLines($filters);
        if ($lines === null) {
            return ['data' => [], 'total' => 0, 'kpis' => $this->emptyCustomerKpis()];
        }

        $grouped = $lines->groupBy('customer_code')->map(function (Collection $group) {
            $first = $group->first();

            return [
                'id' => $first->customer_code,
                'customer_code' => $first->customer_code,
                'customer_name' => $first->customer_name,
                'branch_name' => null,
                'sales_person_name' => null,
                'transaction_count' => $group->count(),
                'qty' => null,
                'amount' => round((float) $group->sum('amount_excl_tax'), 2),
                'tax_amount' => round((float) $group->sum('tax'), 2),
                'amount_incl_tax' => round((float) $group->sum('amount_incl_tax'), 2),
                'last_transaction_date' => $group->max('txn_date'),
            ];
        })->sortByDesc('amount')->values();

        $totalRevenue = round((float) $grouped->sum('amount'), 2);
        $kpis = [
            'total_customers' => $grouped->count(),
            'total_revenue' => $totalRevenue,
            'total_tax' => round((float) $grouped->sum('tax_amount'), 2),
            'total_incl_tax' => round((float) $grouped->sum('amount_incl_tax'), 2),
            'avg_per_customer' => $grouped->count() > 0 ? round($totalRevenue / $grouped->count(), 2) : 0.0,
            'top_customer_name' => $grouped->first()['customer_name'] ?? null,
            'top_customer_amount' => $grouped->first()['amount'] ?? 0.0,
        ];

        return [
            'data' => $grouped->forPage($page, $perPage)->values()->all(),
            'total' => $grouped->count(),
            'kpis' => $kpis,
        ];
    }

    /** Every filtered line (unfiltered by type/payment_status/search -- those apply after), or null if a customer filter was given but doesn't exist in the archive at all. */
    private function baseLines(array $filters): ?Collection
    {
        $customerCode = null;
        if (! empty($filters['customer_id'])) {
            $customerCode = Customer::query()->find($filters['customer_id'])?->customer_code;
            if ($customerCode === null) {
                return null;
            }
        }

        return DB::table('sales_listing_snapshot_lines')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('txn_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('txn_date', '<=', $d))
            ->when($customerCode, fn ($q, $c) => $q->where('customer_code', $c))
            ->get();
    }

    private function mapListingRow(object $line, ?array $arMap): array
    {
        if ($arMap === null) {
            $paymentStatus = null;
            $outstandingAr = null;
        } elseif (array_key_exists($line->document_number, $arMap)) {
            $paymentStatus = 'unpaid';
            $outstandingAr = (float) $arMap[$line->document_number];
        } else {
            $paymentStatus = 'paid';
            $outstandingAr = 0.0;
        }

        return [
            'id' => $line->id,
            'type' => $this->mapTypeLabel($line->type_code),
            'document_number' => $line->document_number,
            'date' => $line->txn_date,
            'reference_so_number' => $line->reference_so,
            'reference_do_number' => $line->reference_do,
            'customer_code' => $line->customer_code,
            'customer_name' => $line->customer_name,
            'sales_person_name' => 'Unassigned',
            'branch_name' => null,
            'amount' => (float) $line->amount_excl_tax,
            'discount' => (float) $line->disc_adjustment,
            'tax' => (float) $line->tax,
            'amount_incl_tax' => (float) $line->amount_incl_tax,
            'payment_status' => $paymentStatus,
            'outstanding_ar' => $outstandingAr,
        ];
    }

    /** Raw Skybiz code -> the live tab's own 'invoice'/'credit_note' enum when recognized, else the raw code untouched -- ticket's explicit "don't discard an unrecognized value" instruction. */
    private function mapTypeLabel(string $rawCode): string
    {
        $lower = strtolower($rawCode);

        if (str_contains($lower, 'cn') || str_contains($lower, 'credit')) {
            return 'credit_note';
        }
        if (str_contains($lower, 'inv')) {
            return 'invoice';
        }

        return $rawCode;
    }

    /** [document_number (ref_no) => unpaid_amount] from the latest Customer Outstanding Bills snapshot, or null if none exists at all -- the one explicitly-allowed cross-archive join. */
    private function arUnpaidMap(): ?array
    {
        $latest = CustomerOutstandingSnapshot::query()->latest('created_at')->first();

        return $latest?->lines()->pluck('unpaid_amount', 'ref_no')->map(fn ($v) => (float) $v)->all();
    }

    private function emptyListingKpis(): array
    {
        return ['net_sales' => 0.0, 'total_tax' => 0.0, 'gross' => 0.0, 'invoice_count' => 0, 'paid_value' => 0.0, 'unpaid_value' => 0.0];
    }

    private function emptyCustomerKpis(): array
    {
        return [
            'total_customers' => 0, 'total_revenue' => 0.0, 'total_tax' => 0.0, 'total_incl_tax' => 0.0,
            'avg_per_customer' => 0.0, 'top_customer_name' => null, 'top_customer_amount' => 0.0,
        ];
    }
}
