<?php

namespace Tests\Feature;

use App\Enums\CreditNoteReason;
use App\Enums\InvoiceType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CreditNoteService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Margin tab — Profit = Penjualan (excl. tax) - HPP, sourced from validated Sales Invoice lines net of Credit Notes. */
class MarginReportTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected DeliveryService $deliveryService;

    protected InvoiceService $invoiceService;

    protected CreditNoteService $creditNoteService;

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
        $this->creditNoteService = app(CreditNoteService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
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

        // Cost (6000/unit) deliberately below the sale rate (10000/unit) below, so Profit/Margin
        // aren't trivially zero.
        $this->seedStock($this->item->id, $this->warehouse->id, 1000, unitCost: 6000);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    /** Full Sales Order -> Delivery -> Invoice chain; qty/rate configurable. */
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
        $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->invoiceService->submit($invoice);
    }

    public function test_invoice_creation_snapshots_cost_from_what_the_delivery_actually_consumed(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 10000);
        $line = $invoice->items->first();

        $this->assertEquals(6000, (float) $line->unit_cost);
        $this->assertEquals(60000, (float) $line->cost_amount);
    }

    public function test_margin_kpis_match_sales_listings_net_sales_for_the_same_filters(): void
    {
        $this->submittedInvoice(qty: 10, rate: 10000); // 100000
        $this->submittedInvoice(qty: 5, rate: 20000); // 100000

        $margin = $this->get('/api/v1/reports/sales/margin')->assertOk()->json('meta.kpis');
        $listing = $this->get('/api/v1/reports/sales/listing')->assertOk()->json('meta.kpis');

        $this->assertEquals($listing['net_sales'], $margin['total_sales']);
        $this->assertEquals(200000, $margin['total_sales']);
        $this->assertEquals(200000 - (10 * 6000 + 5 * 6000), $margin['total_profit']);
    }

    public function test_profit_sum_is_identical_across_item_customer_and_invoice_groupings(): void
    {
        $this->submittedInvoice(qty: 10, rate: 10000);
        $this->submittedInvoice(qty: 5, rate: 20000);

        $byItem = $this->get('/api/v1/reports/sales/margin?group=item')->assertOk()->json('data');
        $byCustomer = $this->get('/api/v1/reports/sales/margin?group=customer')->assertOk()->json('data');
        $byInvoice = $this->get('/api/v1/reports/sales/margin?group=invoice')->assertOk()->json('data');

        $sum = fn (array $rows) => array_sum(array_column($rows, 'profit'));

        $this->assertEquals($sum($byItem), $sum($byCustomer));
        $this->assertEquals($sum($byItem), $sum($byInvoice));
        $this->assertEquals(200000 - 90000, $sum($byItem));
    }

    public function test_a_credit_note_reduces_both_penjualan_and_profit(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 10000); // amount 100000, cost 60000, profit 40000
        $invoiceItem = $invoice->items->first();

        $before = $this->get('/api/v1/reports/sales/margin')->assertOk()->json('meta.kpis');
        $this->assertEquals(100000, $before['total_sales']);
        $this->assertEquals(40000, $before['total_profit']);

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [
                ['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 3, 'amount' => 30000],
            ],
        ]);
        $this->creditNoteService->submit($creditNote);

        $after = $this->get('/api/v1/reports/sales/margin')->assertOk()->json('meta.kpis');

        // Credited 3 of 10 units: -30000 sales, -18000 cost (3 * 6000 unit_cost snapshot), so
        // profit drops by exactly 12000 (30000 - 18000).
        $this->assertEquals(100000 - 30000, $after['total_sales']);
        $this->assertEquals(40000 - 12000, $after['total_profit']);
    }

    public function test_a_transportation_line_is_flagged_and_counted_in_totals_but_excluded_from_average_margin(): void
    {
        $this->submittedInvoice(qty: 10, rate: 10000); // amount 100000, cost 60000, profit 40000 -> 40% margin

        $transportation = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'items' => [['description' => 'Ongkos Angkut', 'qty' => 1, 'rate' => 50000]],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->invoiceService->submit($transportation);

        $kpis = $this->get('/api/v1/reports/sales/margin')->assertOk()->json('meta.kpis');

        // Totals include the Transportation line's full amount as profit (cost 0)...
        $this->assertEquals(150000, $kpis['total_sales']);
        $this->assertEquals(150000 - 60000, $kpis['total_profit']);
        // ...but the average margin is computed only from the Goods line (40%), not dragged
        // toward 100% by the Transportation line's cost-free amount.
        $this->assertEquals(40.0, $kpis['avg_margin_pct']);

        $rows = $this->get('/api/v1/reports/sales/margin?group=item')->assertOk()->json('data');
        $flagged = collect($rows)->firstWhere('hpp_missing', true);
        $this->assertNotNull($flagged);
        $this->assertEquals(50000, $flagged['amount']);
    }
}
