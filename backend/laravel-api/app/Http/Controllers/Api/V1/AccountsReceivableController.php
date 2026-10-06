<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\AccountsReceivableAgingDetailExport;
use App\Exports\AccountsReceivableAgingSummaryExport;
use App\Exports\AccountsReceivableLedgerExport;
use App\Exports\OpenBillsByCustomerExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAccountsReceivableRequest;
use App\Http\Requests\LedgerAccountsReceivableRequest;
use App\Enums\DocumentStatus;
use App\Http\Resources\AccountsReceivableResource;
use App\Models\AccountsReceivable;
use App\Services\AccountsReceivableAgingReportService;
use App\Services\AccountsReceivableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only — Accounts Receivable rows are only ever created as a side
 * effect of InvoiceService::submit(). No store/update/destroy.
 */
class AccountsReceivableController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountsReceivableAgingReportService $accountsReceivableAgingReportService,
    ) {}

    public function index(IndexAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(
            AccountsReceivableResource::collection($this->accountsReceivableService->list($filters, $perPage)),
            '',
            200,
            ['total_outstanding' => $this->accountsReceivableService->outstandingTotal($filters)]
        );
    }

    /**
     * Customer Outstanding Bills (live) — every still-owed AR row per customer, as at a date. Replaces the
     * static Skybiz archive view: a payment that clears a row drops it from here, the same way Skybiz's own
     * unpaid-bills report behaves. Read-only; nothing here is written back.
     */
    public function openByCustomer(Request $request): JsonResponse
    {
        return $this->success($this->openBillsPayload($this->asAtFrom($request)));
    }

    /** Same numbers as openByCustomer(), flattened to one xlsx row per open document for reconciliation against an external statement. */
    public function exportOpenByCustomer(Request $request): BinaryFileResponse
    {
        $payload = $this->openBillsPayload($this->asAtFrom($request));

        $export = new OpenBillsByCustomerExport($payload['customers'], $payload['grand_total_unpaid'], $payload['grand_total_overdue'], $payload['as_at']);

        return Excel::download($export, "PiutangCustomerLive_{$payload['as_at']}.xlsx");
    }

    private function asAtFrom(Request $request): \Illuminate\Support\Carbon
    {
        return $request->query('as_at') ? \Illuminate\Support\Carbon::parse($request->query('as_at'))->startOfDay() : now()->startOfDay();
    }

    /** @return array{as_at: string, customers: \Illuminate\Support\Collection, grand_total_unpaid: float, grand_total_overdue: float} */
    private function openBillsPayload(\Illuminate\Support\Carbon $asAt): array
    {
        $rows = AccountsReceivable::query()
            ->with(['customer:id,customer_code,customer_name', 'invoice:id,document_number,invoice_date,reference_1,status,terms_of_payment_id', 'invoice.termsOfPayment:id,days'])
            ->whereRaw('amount - paid_amount > 0.005')
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', DocumentStatus::CANCELLED->value)->whereDate('invoice_date', '<=', $asAt))
            ->get();

        $groups = $rows
            ->groupBy('customer_id')
            ->map(function ($customerRows) use ($asAt) {
                $customer = $customerRows->first()->customer;
                $lines = $customerRows
                    ->sortBy(fn (AccountsReceivable $ar) => $ar->invoice?->invoice_date?->format('Y-m-d'))
                    ->values()
                    ->map(function (AccountsReceivable $ar) use ($asAt) {
                        $unpaid = round((float) $ar->amount - (float) $ar->paid_amount, 2);
                        $overdueDays = $ar->due_date && $ar->due_date->lt($asAt) ? (int) $ar->due_date->diffInDays($asAt) : 0;

                        return [
                            'invoice_date' => $ar->invoice?->invoice_date?->format('Y-m-d'),
                            'document_number' => $ar->invoice?->document_number,
                            'reference_1' => $ar->invoice?->reference_1,
                            'amount' => round((float) $ar->amount, 2),
                            'paid_amount' => round((float) $ar->paid_amount, 2),
                            'unpaid_amount' => $unpaid,
                            'terms_days' => $ar->invoice?->termsOfPayment?->days,
                            'due_date' => $ar->due_date?->format('Y-m-d'),
                            'overdue_days' => $overdueDays,
                            'overdue_amount' => $overdueDays > 0 ? $unpaid : 0.0,
                        ];
                    });

                return [
                    'customer_code' => $customer?->customer_code,
                    'customer_name' => $customer?->customer_name,
                    'rows' => $lines,
                    'total_unpaid' => round($lines->sum('unpaid_amount'), 2),
                    'total_overdue' => round($lines->sum('overdue_amount'), 2),
                ];
            })
            ->sortBy('customer_code')
            ->values();

        return [
            'as_at' => $asAt->toDateString(),
            'customers' => $groups,
            'grand_total_unpaid' => round($groups->sum('total_unpaid'), 2),
            'grand_total_overdue' => round($groups->sum('total_overdue'), 2),
        ];
    }

    /** F1 (UAT review 2026-08-12) — "Tanda Terima Invoice": same filters as index(), unpaginated (listAll() — never truncated at the 100/page cap a single customer's invoice list could otherwise hit). */
    public function listAll(IndexAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['per_page']);

        return $this->success(AccountsReceivableResource::collection($this->accountsReceivableService->listAll($filters)));
    }

    /** C3 (UAT review 2026-08-12) — "Perincian Piutang": same filters as index(), grouped Sales Person -> Customer with subtotals. */
    public function detailGrouped(IndexAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['per_page']);

        return $this->success($this->accountsReceivableService->groupedDetail($filters));
    }

    /**
     * C2 (UAT review 2026-08-12), rebuilt to match the client's real legacy-system export files
     * ("Customer Detail Aging" / "Customer Summary Aging") — same filters as index(), unpaginated,
     * plus a `type` choice. `invoice_ids` (already validated/wired in filteredQuery()) doubles as
     * the "export only selected rows" mechanism — a non-empty selection narrows $rows the same way
     * a filter does, so every subtotal/Grand Total/Summary figure the report service computes
     * from $rows falls out already scoped to the selection, no separate code path needed.
     */
    public function export(IndexAccountsReceivableRequest $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'type' => ['sometimes', Rule::in(['detail', 'summary'])],
        ]);
        $format = $validated['format'] ?? 'xlsx';
        $type = $validated['type'] ?? 'detail';
        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->accountsReceivableAgingReportService->rows($filters);

        if ($type === 'summary') {
            $built = $this->accountsReceivableAgingReportService->summaryReport($rows, $format);
            $export = new AccountsReceivableAgingSummaryExport($built['rows'], $built['meta']);
            $filename = "CustomerSummaryAging.{$format}";
        } else {
            $built = $this->accountsReceivableAgingReportService->detailReport($rows, $format);
            $export = new AccountsReceivableAgingDetailExport($built['rows'], $built['meta']);
            $filename = "CustomerDetailAging.{$format}";
        }

        return Excel::download($export, $filename);
    }

    public function show(AccountsReceivable $accountsReceivable): JsonResponse
    {
        return $this->success(new AccountsReceivableResource($accountsReceivable->load(['customer', 'invoice', 'salesOrder', 'delivery'])));
    }

    /** Kartu Piutang's on-screen table — one customer, paginated. */
    public function ledger(LedgerAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;
        $ledger = $this->accountsReceivableService->ledger($filters, $perPage);
        $meta = $ledger['meta'];
        unset($ledger['meta']);

        return $this->success($ledger, '', 200, $meta);
    }

    /** Kartu Piutang's print preview — same core as ledger(), unpaginated so the statement covers the whole filtered period. */
    public function ledgerPrint(LedgerAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['page'], $filters['per_page']);

        return $this->success($this->accountsReceivableService->ledgerFull($filters));
    }

    /** Kartu Piutang's Export CSV/XLSX — same unpaginated core as ledgerPrint(), flattened into one array sheet. */
    public function ledgerExport(LedgerAccountsReceivableRequest $request): BinaryFileResponse
    {
        $validated = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]]);
        $format = $validated['format'] ?? 'xlsx';
        $filters = $request->validated();
        unset($filters['page'], $filters['per_page'], $filters['format']);

        $ledger = $this->accountsReceivableService->ledgerFull($filters);
        $rows = $this->buildLedgerExportRows($ledger);

        $customerCode = $ledger['header']['customer_code'] ?? 'Customer';

        return Excel::download(new AccountsReceivableLedgerExport($rows), "KartuPiutang_{$customerCode}.{$format}");
    }

    /** Flattens ledgerFull()'s structured shape into one array sheet — header info, blank, column headers, transaction rows, closing balance, blank, aging summary. */
    private function buildLedgerExportRows(array $ledger): array
    {
        $header = $ledger['header'];
        $rows = [
            ['Kartu Piutang'],
            ['Pelanggan', "{$header['customer_code']} - {$header['customer_name']}"],
            ['Branch', $header['branch_name'] ?? '-'],
            ['Sales Person', $header['sales_person_name'] ?? '-'],
            ['Terms of Payment', $header['terms_of_payment_name'] ?? '-'],
            [],
            ['Tanggal', 'Jenis Dokumen', 'Nomor Dokumen', 'Keterangan', 'Jatuh Tempo', 'Debit', 'Kredit', 'Saldo Berjalan'],
            ['', '', '', 'Saldo Awal', '', '', '', $ledger['opening_balance']],
        ];

        foreach ($ledger['rows'] as $row) {
            $rows[] = [
                $row['date'],
                $row['document_type'],
                $row['document_number'],
                $row['description'],
                $row['due_date'],
                $row['debit'],
                $row['credit'],
                $row['running_balance'],
            ];
        }

        $rows[] = ['', '', '', 'Saldo Akhir', '', '', '', $ledger['closing_balance']];
        $rows[] = [];
        $rows[] = ['Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '61-90 Hari', '> 90 Hari'];
        $rows[] = [
            $ledger['aging']['not_due'],
            $ledger['aging']['due_1_30'],
            $ledger['aging']['due_31_60'],
            $ledger['aging']['due_61_90'],
            $ledger['aging']['due_over_90'],
        ];

        return $rows;
    }
}
