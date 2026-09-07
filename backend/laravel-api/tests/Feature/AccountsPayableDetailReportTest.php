<?php

namespace Tests\Feature;

use App\Models\AccountsPayable;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntryLine;
use App\Models\PaymentEntry;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Repositories\AccountsPayableRepository;
use App\Services\AccountsPayableService;
use App\Services\GoodsReceiptService;
use App\Services\PaymentEntryAllocationService;
use App\Services\PaymentEntryService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AP mirror of AccountsReceivableDetailReportTest — same coverage shape, retargeted at AccountsPayable/PurchaseInvoice/Supplier and due-date-anchored aging buckets (see AccountsPayableRepository::groupedBySupplierAgingBuckets() for why AP's bucket scheme differs from AR's). */
class AccountsPayableDetailReportTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected PurchaseInvoiceService $purchaseInvoiceService;
    protected PaymentEntryService $paymentEntryService;
    protected PaymentEntryAllocationService $paymentEntryAllocationService;
    protected AccountsPayableRepository $accountsPayableRepository;
    protected AccountsPayableService $accountsPayableService;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);
        $this->purchaseInvoiceService = app(PurchaseInvoiceService::class);
        $this->paymentEntryService = app(PaymentEntryService::class);
        $this->paymentEntryAllocationService = app(PaymentEntryAllocationService::class);
        $this->accountsPayableRepository = app(AccountsPayableRepository::class);
        $this->accountsPayableService = app(AccountsPayableService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => \App\Enums\WarehouseType::MAIN]);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    protected function submittedPurchaseInvoice(string $dueDate, float $rate = 20000, ?Warehouse $warehouse = null, ?Supplier $supplier = null): AccountsPayable
    {
        $supplier ??= $this->supplier;
        $warehouse ??= $this->warehouse;

        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => $rate]],
        ]);
        $this->approveDocument($purchaseOrder);
        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 5]],
        ]);
        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => $dueDate,
        ]);
        $this->purchaseInvoiceService->submit($purchaseInvoice);

        return AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
    }

    public function test_date_range_filters_ap_rows_by_due_date(): void
    {
        $this->submittedPurchaseInvoice('2026-01-15');
        $this->submittedPurchaseInvoice('2026-06-15');

        $filtered = $this->accountsPayableRepository->search([
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $this->assertCount(1, $filtered->items());
        $this->assertEquals('2026-01-15', $filtered->items()[0]->due_date->toDateString());
    }

    public function test_status_and_supplier_filters_still_work_alongside_date_range(): void
    {
        $this->submittedPurchaseInvoice('2026-03-01');

        $results = $this->accountsPayableRepository->search([
            'supplier_id' => $this->supplier->id,
            'status' => 'unpaid',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $this->assertCount(1, $results->items());
    }

    /** Purchase has no Branch concept anywhere — Warehouse is the real, always-present dimension for these documents (every AccountsPayable row's Goods Receipt has a NOT NULL warehouse_id). */
    public function test_warehouse_filter_narrows_by_the_underlying_goods_receipt(): void
    {
        $otherWarehouse = Warehouse::query()->create(['name' => 'Second WH', 'code' => 'WH2', 'warehouse_type' => \App\Enums\WarehouseType::MAIN]);

        $this->submittedPurchaseInvoice(now()->addDays(10)->toDateString(), warehouse: $this->warehouse);
        $this->submittedPurchaseInvoice(now()->addDays(10)->toDateString(), warehouse: $otherWarehouse);

        $byWarehouse = $this->accountsPayableRepository->search(['warehouse_id' => $otherWarehouse->id]);

        $this->assertCount(1, $byWarehouse->items());
        $this->assertEquals($otherWarehouse->id, $byWarehouse->items()[0]->goodsReceipt->warehouse_id);
    }

    /** aging_bucket is a ceiling filter ("overdue up to N days"), same semantics as AR's filter dropdown — never a discrete non-overlapping bucket. */
    public function test_aging_bucket_30_includes_exactly_30_days_overdue_and_excludes_31(): void
    {
        $this->submittedPurchaseInvoice(now()->subDays(30)->toDateString());
        $this->submittedPurchaseInvoice(now()->subDays(31)->toDateString());

        $results = $this->accountsPayableRepository->search(['aging_bucket' => '30']);

        $this->assertCount(1, $results->items());
        $this->assertEquals(now()->subDays(30)->toDateString(), $results->items()[0]->due_date->toDateString());
    }

    public function test_outstanding_total_sums_the_same_filtered_set_as_search(): void
    {
        $this->submittedPurchaseInvoice(now()->subDays(10)->toDateString()); // 100000
        $this->submittedPurchaseInvoice(now()->subDays(40)->toDateString()); // 100000, outside aging_bucket=30

        $total = $this->accountsPayableRepository->outstandingTotal(['aging_bucket' => '30']);

        $this->assertEquals(100000.0, $total);
    }

    /**
     * "Perincian Hutang"'s discrete, due-date-anchored bucket boundaries —
     * a different scheme from the aging_bucket *filter* tested above.
     * Exercises every boundary day: today (not_due), today-1 and today-30
     * (due_1_30), today-31 and today-60 (due_31_60), today-61 and today-90
     * (due_61_90), today-91 (due_over_90).
     */
    public function test_grouped_detail_buckets_land_on_the_correct_side_of_every_boundary(): void
    {
        $this->submittedPurchaseInvoice(now()->toDateString(), rate: 100); // not_due: 500
        $this->submittedPurchaseInvoice(now()->subDay()->toDateString(), rate: 100); // due_1_30: 500
        $this->submittedPurchaseInvoice(now()->subDays(30)->toDateString(), rate: 100); // due_1_30: 500
        $this->submittedPurchaseInvoice(now()->subDays(31)->toDateString(), rate: 100); // due_31_60: 500
        $this->submittedPurchaseInvoice(now()->subDays(60)->toDateString(), rate: 100); // due_31_60: 500
        $this->submittedPurchaseInvoice(now()->subDays(61)->toDateString(), rate: 100); // due_61_90: 500
        $this->submittedPurchaseInvoice(now()->subDays(90)->toDateString(), rate: 100); // due_61_90: 500
        $this->submittedPurchaseInvoice(now()->subDays(91)->toDateString(), rate: 100); // due_over_90: 500

        $rows = $this->accountsPayableRepository->groupedBySupplierAgingBuckets([]);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertEquals(500.0, $row['not_due']);
        $this->assertEquals(1000.0, $row['due_1_30']);
        $this->assertEquals(1000.0, $row['due_31_60']);
        $this->assertEquals(1000.0, $row['due_61_90']);
        $this->assertEquals(500.0, $row['due_over_90']);
        $this->assertEquals(4000.0, $row['total']);
    }

    public function test_unallocated_payment_voucher_excludes_fully_allocated_and_never_affects_any_payable(): void
    {
        $accountsPayable = $this->submittedPurchaseInvoice(now()->addDays(30)->toDateString()); // 100000

        $unallocatedPayment = PaymentEntry::query()->create([
            'payment_type' => 'supplier', 'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'), 'total_amount' => 50000, 'allocated_amount' => 0,
        ])->submit();

        $fullyAllocatedPayment = $this->paymentEntryService->create([
            'payment_type' => 'supplier', 'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'), 'amount' => 100000,
        ]);
        $fullyAllocatedPayment = $this->paymentEntryService->submit($fullyAllocatedPayment);
        $this->paymentEntryAllocationService->allocateBatch($fullyAllocatedPayment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 100000],
        ]);

        $unallocated = $this->accountsPayableService->unallocatedPaymentVouchers();

        $this->assertCount(1, $unallocated);
        $this->assertEquals($unallocatedPayment->id, $unallocated->first()->id);
        // unallocated_amount_computed — the live SUM-based figure the API actually returns —
        // not the model's own unallocatedAmount() (which trusts the allocated_amount cache).
        $this->assertEquals(50000.0, $unallocated->first()->unallocated_amount_computed);
        // Only the fully-allocated payment's 100000 reduced this invoice's outstanding balance —
        // the unallocated payment's 50000 sitting in the panel never touched it. Read via the
        // report's own ground-truth (live PaymentEntryAllocation sum), not the cache column.
        $paidComputed = $this->accountsPayableRepository->paidAmountFor($accountsPayable->id);
        $this->assertEquals(100000.0, $paidComputed);
        $this->assertEquals(0.0, (float) $accountsPayable->fresh()->amount - $paidComputed);
    }

    /** Ticket requirement #1: a partially-paid invoice's Sudah Dibayar + Sisa Hutang must equal Total Invoice. */
    public function test_partially_paid_invoice_paid_plus_outstanding_equals_total_invoice(): void
    {
        $accountsPayable = $this->submittedPurchaseInvoice(now()->addDays(30)->toDateString()); // 100000

        $payment = $this->paymentEntryService->create([
            'payment_type' => 'supplier', 'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'), 'amount' => 35000,
        ]);
        $payment = $this->paymentEntryService->submit($payment);
        $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 35000],
        ]);

        $row = $this->accountsPayableRepository->search(['supplier_id' => $this->supplier->id])->items()[0];
        $paid = $this->accountsPayableRepository->paidAmountFor($accountsPayable->id);
        $outstanding = (float) $row->amount - $paid;

        $this->assertEquals(35000.0, $paid);
        $this->assertEquals(65000.0, $outstanding);
        $this->assertEquals((float) $row->amount, $paid + $outstanding);
    }

    /**
     * Ticket requirement #2: one payment allocated to TWO invoices in a
     * single allocateBatch() call reduces each by its own portion only —
     * no double-counting. Both AccountsPayable rows are for the same
     * supplier here (allocateBatch operates per-payment, not per-supplier,
     * but two different suppliers on one payment isn't a real scenario the
     * business allows, so same-supplier is the representative case).
     */
    public function test_one_payment_allocated_to_two_invoices_reduces_each_by_its_own_portion(): void
    {
        $ap1 = $this->submittedPurchaseInvoice(now()->addDays(30)->toDateString(), rate: 20000); // 100000
        $ap2 = $this->submittedPurchaseInvoice(now()->addDays(45)->toDateString(), rate: 12000); // 60000

        $payment = $this->paymentEntryService->create([
            'payment_type' => 'supplier', 'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'), 'amount' => 130000,
        ]);
        $payment = $this->paymentEntryService->submit($payment);
        $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $ap1->id, 'amount' => 100000],
            ['accounts_payable_id' => $ap2->id, 'amount' => 30000],
        ]);

        $paid1 = $this->accountsPayableRepository->paidAmountFor($ap1->id);
        $paid2 = $this->accountsPayableRepository->paidAmountFor($ap2->id);

        $this->assertEquals(100000.0, $paid1);
        $this->assertEquals(30000.0, $paid2);
        // Neither figure leaked into the other — one payment split two ways, not double-applied.
        $this->assertEquals(0.0, (float) $ap1->fresh()->amount - $paid1);
        $this->assertEquals(30000.0, (float) $ap2->fresh()->amount - $paid2);

        $totalOutstanding = $this->accountsPayableRepository->outstandingTotal(['supplier_id' => $this->supplier->id]);
        $this->assertEquals(30000.0, $totalOutstanding);
    }

    /** Summary cards route through outstandingTotal()/unallocatedPaymentVouchers() — same ground truth as everything else, never a separate figure. */
    public function test_summary_cards_use_the_same_ground_truth_as_the_main_report(): void
    {
        $overdue = $this->submittedPurchaseInvoice(now()->subDays(5)->toDateString(), rate: 100); // 500, overdue
        $dueThisWeek = $this->submittedPurchaseInvoice(now()->addDays(2)->toDateString(), rate: 200); // 1000, due this week
        $far = $this->submittedPurchaseInvoice(now()->addDays(60)->toDateString(), rate: 300); // 1500, neither

        PaymentEntry::query()->create([
            'payment_type' => 'supplier', 'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'), 'total_amount' => 7000, 'allocated_amount' => 0,
        ])->submit();

        $summary = $this->accountsPayableService->summaryCards();

        $this->assertEquals(3000.0, $summary['total_outstanding']); // 500 + 1000 + 1500
        $this->assertEquals(1000.0, $summary['due_this_week']);
        $this->assertEquals(500.0, $summary['overdue']);
        $this->assertEquals(7000.0, $summary['unallocated_total']);

        // Cross-check against the same method the main list uses, not a hand re-derived number.
        $this->assertEquals($this->accountsPayableRepository->outstandingTotal([]), $summary['total_outstanding']);
    }

    /** Ticket requirement #5: a Draft Purchase Invoice never produces an AccountsPayable row, so it never appears in any AP Detail query. */
    public function test_draft_purchase_invoice_never_appears(): void
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000]],
        ]);
        $this->approveDocument($purchaseOrder);
        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 5]],
        ]);
        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);

        // Created but deliberately never submit()'d.
        $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertCount(0, AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->get());
        $this->assertCount(0, $this->accountsPayableRepository->search([])->items());
        $this->assertEquals(0.0, $this->accountsPayableRepository->outstandingTotal([]));
    }

    /**
     * Structural GL-tie-out proof: accounts_payables' computed outstanding
     * and GL account 2000's net balance agree by construction (Purchase
     * Invoice credits 2000 by the same grand_total AP.amount uses; a
     * settled allocation debits 2000 by exactly the amount AP.paid_amount
     * gains) — not a coincidence of matching test data.
     */
    public function test_outstanding_total_ties_to_gl_account_2000_balance(): void
    {
        $accountsPayable = $this->submittedPurchaseInvoice(now()->addDays(30)->toDateString()); // 100000

        $payment = $this->paymentEntryService->create([
            'payment_type' => 'supplier', 'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'), 'amount' => 40000,
        ]);
        $payment = $this->paymentEntryService->submit($payment);
        $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 40000],
        ]);

        $outstandingTotal = $this->accountsPayableRepository->outstandingTotal([]);
        $this->assertEquals(60000.0, $outstandingTotal);

        $account2000Id = $this->accountId('2000');
        $netCredit = JournalEntryLine::query()->where('chart_of_account_id', $account2000Id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
            ->value('net');

        $this->assertEquals($outstandingTotal, (float) $netCredit);
    }
}
