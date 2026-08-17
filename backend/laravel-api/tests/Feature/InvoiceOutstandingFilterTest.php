<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\ReceiptEntry;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Point 4C — the /invoices?outstanding=1 filter reuses AccountsReceivable.status
 * (Unpaid/Partial) as-is, no recomputation. Paid/Draft/Cancelled must not appear.
 */
class InvoiceOutstandingFilterTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected PaymentAllocationService $paymentAllocationService;
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
        $this->paymentAllocationService = app(PaymentAllocationService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => \App\Enums\WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        app(\App\Services\StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: \App\Enums\StockTransactionType::IN,
            voucherType: \App\Enums\StockVoucherType::STOCK_IN,
            voucherId: (string) \Illuminate\Support\Str::uuid(),
            qtyChange: 1000,
            postingDatetime: now(),
        );

        Permission::query()->firstOrCreate(['name' => 'sales.invoices.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('sales.invoices.view');
        Sanctum::actingAs($viewer);
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    protected function submittedInvoice(int $qty = 10, float $rate = 10000): Invoice
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $delivery = $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->invoiceService->submit($invoice);
    }

    protected function pay(Invoice $invoice, float $amount): void
    {
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'total_amount' => $amount,
            'allocated_amount' => 0,
        ])->submit();
        $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => $amount],
        ]);
    }

    public function test_unpaid_invoice_appears_under_outstanding(): void
    {
        $invoice = $this->submittedInvoice();

        $response = $this->getJson('/api/v1/invoices?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertTrue($documentNumbers->contains($invoice->fresh()->document_number));
    }

    public function test_partially_paid_invoice_appears_under_outstanding(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 10000); // grand_total 100000
        $this->pay($invoice, 40000);

        $response = $this->getJson('/api/v1/invoices?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertTrue($documentNumbers->contains($invoice->fresh()->document_number));
    }

    public function test_paid_invoice_is_excluded(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 10000); // grand_total 100000
        $this->pay($invoice, 100000);

        $response = $this->getJson('/api/v1/invoices?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertFalse($documentNumbers->contains($invoice->fresh()->document_number));
    }

    public function test_draft_invoice_is_excluded(): void
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 10000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);
        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 10]],
        ]);
        $delivery = $this->deliveryService->complete($delivery);
        $draft = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $response = $this->getJson('/api/v1/invoices?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertFalse($documentNumbers->contains($draft->fresh()->document_number));
    }

    public function test_cancelled_invoice_is_excluded(): void
    {
        $invoice = $this->submittedInvoice();
        $this->invoiceService->cancel($invoice);

        $response = $this->getJson('/api/v1/invoices?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertFalse($documentNumbers->contains($invoice->fresh()->document_number));
    }

    /**
     * Isolates what the `outstanding` filter itself costs, at a fixed row count — rather than
     * comparing N=1 vs N=5 under the filter. InvoiceItemResource already runs one
     * credit_note_items SUM query per invoice item on every /invoices request today (pre-existing,
     * present on "Semua" too, unrelated to this filter) — an N=1-vs-N=5 comparison would flag
     * that instead of what this ticket actually touches. Comparing filtered vs unfiltered at the
     * same N isolates the `whereHas('accountsReceivable', ...)` clause added in
     * InvoiceRepository::search(): it's one EXISTS clause on the same query, so it must not add
     * any queries on top of the unfiltered baseline.
     */
    public function test_outstanding_filter_adds_no_queries_over_the_unfiltered_baseline_at_the_same_row_count(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->submittedInvoice();
        }
        // Warm-up request first — the very first authenticated request in a test process pays a
        // one-time Spatie permission-cache query that has nothing to do with the filter; without
        // this warm-up it would look like a false N+1 signal.
        $this->getJson('/api/v1/invoices')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/v1/invoices')->assertOk();
        $queriesUnfiltered = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        DB::enableQueryLog();
        $this->getJson('/api/v1/invoices?outstanding=1')->assertOk();
        $queriesFiltered = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesUnfiltered, $queriesFiltered, 'The outstanding filter must not add queries on top of the unfiltered baseline (no N+1 from the added whereHas).');
    }
}
