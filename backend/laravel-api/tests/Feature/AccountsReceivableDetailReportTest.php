<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Repositories\AccountsReceivableRepository;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Batch B — AR Detail Report extends the existing Accounts Receivable list with due_date-bounded date filters; no new endpoint. */
class AccountsReceivableDetailReportTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected AccountsReceivableRepository $accountsReceivableRepository;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->accountsReceivableRepository = app(AccountsReceivableRepository::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);

        app(StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 1000,
            postingDatetime: now(),
        );
    }

    protected function submittedInvoice(string $dueDate): Invoice
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);
        $this->deliveryService->submit($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => $dueDate,
        ]);

        return $this->invoiceService->submit($invoice);
    }

    public function test_date_range_filters_ar_rows_by_due_date(): void
    {
        $this->submittedInvoice('2026-01-15');
        $this->submittedInvoice('2026-06-15');

        $filtered = $this->accountsReceivableRepository->search([
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $this->assertCount(1, $filtered->items());
        $this->assertEquals('2026-01-15', $filtered->items()[0]->due_date->toDateString());
    }

    public function test_status_and_customer_filters_still_work_alongside_date_range(): void
    {
        $this->submittedInvoice('2026-03-01');

        $results = $this->accountsReceivableRepository->search([
            'customer_id' => $this->customer->id,
            'status' => 'unpaid',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $this->assertCount(1, $results->items());
    }

    /** aging_bucket is a ceiling filter ("overdue up to N days"), never a discrete non-overlapping bucket. */
    public function test_aging_bucket_30_includes_exactly_30_days_overdue_and_excludes_31(): void
    {
        $this->submittedInvoice(now()->subDays(30)->toDateString());
        $this->submittedInvoice(now()->subDays(31)->toDateString());

        $results = $this->accountsReceivableRepository->search(['aging_bucket' => '30']);

        $this->assertCount(1, $results->items());
        $this->assertEquals(now()->subDays(30)->toDateString(), $results->items()[0]->due_date->toDateString());
    }

    public function test_aging_bucket_60_includes_both_a_30_day_and_a_59_day_overdue_invoice(): void
    {
        $this->submittedInvoice(now()->subDays(30)->toDateString());
        $this->submittedInvoice(now()->subDays(59)->toDateString());
        $this->submittedInvoice(now()->subDays(61)->toDateString());

        $results = $this->accountsReceivableRepository->search(['aging_bucket' => '60']);

        $this->assertCount(2, $results->items());
    }

    public function test_aging_bucket_over_180_only_includes_181_plus_days_overdue(): void
    {
        $this->submittedInvoice(now()->subDays(180)->toDateString());
        $this->submittedInvoice(now()->subDays(181)->toDateString());

        $results = $this->accountsReceivableRepository->search(['aging_bucket' => 'over_180']);

        $this->assertCount(1, $results->items());
        $this->assertEquals(now()->subDays(181)->toDateString(), $results->items()[0]->due_date->toDateString());
    }

    public function test_not_yet_due_invoice_excluded_from_every_specific_bucket_but_present_unfiltered(): void
    {
        $this->submittedInvoice(now()->addDays(10)->toDateString());

        $this->assertCount(0, $this->accountsReceivableRepository->search(['aging_bucket' => '30'])->items());
        $this->assertCount(1, $this->accountsReceivableRepository->search([])->items());
    }

    public function test_outstanding_total_sums_the_same_filtered_set_as_search(): void
    {
        $this->submittedInvoice(now()->subDays(10)->toDateString()); // 100000
        $this->submittedInvoice(now()->subDays(40)->toDateString()); // 100000, outside aging_bucket=30

        $total = $this->accountsReceivableRepository->outstandingTotal(['aging_bucket' => '30']);

        $this->assertEquals(100000.0, $total);
    }
}
