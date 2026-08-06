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
use App\Services\AccountsReceivableService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Batch B — AR Aging Report's genuinely new logic: bucketing every open receivable by days past due, grouped by customer. */
class AccountsReceivableAgingReportTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected AccountsReceivableService $accountsReceivableService;
    protected Customer $customerA;
    protected Customer $customerB;
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
        $this->accountsReceivableService = app(AccountsReceivableService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customerA = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->customerB = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Beta']);

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

    protected function submittedInvoice(Customer $customer, string $dueDate, float $rate = 20000): Invoice
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => $rate]],
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

    public function test_bucket_boundaries_at_30_60_and_90_days_past_due(): void
    {
        $asOf = Carbon::parse('2026-06-01');

        $this->submittedInvoice($this->customerA, $asOf->copy()->subDays(30)->toDateString()); // exactly 30 -> 0-30
        $this->submittedInvoice($this->customerA, $asOf->copy()->subDays(31)->toDateString()); // exactly 31 -> 31-60
        $this->submittedInvoice($this->customerA, $asOf->copy()->subDays(60)->toDateString()); // exactly 60 -> 31-60
        $this->submittedInvoice($this->customerA, $asOf->copy()->subDays(61)->toDateString()); // exactly 61 -> 61-90
        $this->submittedInvoice($this->customerA, $asOf->copy()->subDays(90)->toDateString()); // exactly 90 -> 61-90
        $this->submittedInvoice($this->customerA, $asOf->copy()->subDays(91)->toDateString()); // exactly 91 -> over 90

        $result = $this->accountsReceivableService->summarizeAging(['as_of_date' => $asOf->toDateString()]);
        $row = collect($result['rows'])->firstWhere('customer.id', $this->customerA->id);

        $this->assertEquals(100000.0, $row['bucket_0_30']); // 1 invoice x 100000
        $this->assertEquals(200000.0, $row['bucket_31_60']); // 2 invoices
        $this->assertEquals(200000.0, $row['bucket_61_90']); // 2 invoices
        $this->assertEquals(100000.0, $row['bucket_over_90']); // 1 invoice
        $this->assertEquals(600000.0, $row['total_outstanding']);
    }

    public function test_not_yet_due_receivable_lands_in_0_30(): void
    {
        $asOf = Carbon::parse('2026-06-01');
        $this->submittedInvoice($this->customerA, $asOf->copy()->addDays(10)->toDateString());

        $result = $this->accountsReceivableService->summarizeAging(['as_of_date' => $asOf->toDateString()]);
        $row = collect($result['rows'])->firstWhere('customer.id', $this->customerA->id);

        $this->assertEquals(100000.0, $row['bucket_0_30']);
        $this->assertEquals(0.0, $row['bucket_31_60']);
    }

    public function test_fully_paid_receivable_is_excluded_entirely(): void
    {
        $invoice = $this->submittedInvoice($this->customerA, now()->subDays(10)->toDateString());
        $ar = $invoice->accountsReceivable()->firstOrFail();
        $this->accountsReceivableService->settle($ar, (float) $ar->amount);

        $result = $this->accountsReceivableService->summarizeAging([]);
        $row = collect($result['rows'])->firstWhere('customer.id', $this->customerA->id);

        $this->assertNull($row);
    }

    public function test_two_open_receivables_for_same_customer_sum_into_one_row(): void
    {
        $this->submittedInvoice($this->customerA, now()->subDays(10)->toDateString());
        $this->submittedInvoice($this->customerA, now()->subDays(15)->toDateString());

        $result = $this->accountsReceivableService->summarizeAging([]);

        $this->assertCount(1, $result['rows']);
        $this->assertEquals(200000.0, $result['rows'][0]['total_outstanding']);
    }

    public function test_customer_id_filter_narrows_to_that_customer(): void
    {
        $this->submittedInvoice($this->customerA, now()->subDays(10)->toDateString());
        $this->submittedInvoice($this->customerB, now()->subDays(10)->toDateString());

        $result = $this->accountsReceivableService->summarizeAging(['customer_id' => $this->customerA->id]);

        $this->assertCount(1, $result['rows']);
        $this->assertEquals($this->customerA->id, $result['rows'][0]['customer']->id);
    }

    public function test_as_of_date_shifts_bucket_placement_on_recomputation(): void
    {
        $this->submittedInvoice($this->customerA, '2026-05-01');

        $early = $this->accountsReceivableService->summarizeAging(['as_of_date' => '2026-05-15']); // 14 days past due
        $earlyRow = collect($early['rows'])->firstWhere('customer.id', $this->customerA->id);
        $this->assertEquals(100000.0, $earlyRow['bucket_0_30']);

        $later = $this->accountsReceivableService->summarizeAging(['as_of_date' => '2026-08-01']); // 92 days past due
        $laterRow = collect($later['rows'])->firstWhere('customer.id', $this->customerA->id);
        $this->assertEquals(100000.0, $laterRow['bucket_over_90']);
    }
}
