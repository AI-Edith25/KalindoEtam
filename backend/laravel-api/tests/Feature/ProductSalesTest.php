<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Product Sales tab — one row per item, aggregated over invoice_items via a real DB SUM/GROUP BY.
 * The KPI tests specifically prove the fix for the old page's "sums only the loaded page" bug:
 * they create more matching rows than fit on one page and assert the KPI still reflects all of them.
 */
class ProductSalesTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected ItemGroup $itemGroup;

    protected UnitOfMeasurement $uom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->itemGroup = ItemGroup::query()->create(['name' => 'Hardware']);
        $this->uom = UnitOfMeasurement::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    protected function makeItem(string $code, string $name): Item
    {
        return Item::query()->create([
            'item_code' => $code, 'item_name' => $name, 'item_group_id' => $this->itemGroup->id,
            'uom_id' => $this->uom->id, 'standard_rate' => 1000,
        ]);
    }

    protected function invoicedLine(Item $item, int $qty, float $rate, string $date = null, string $status = 'submitted'): void
    {
        $amount = $qty * $rate;
        $invoiceDate = $date ?? now()->toDateString();
        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods', 'status' => $status, 'customer_id' => $this->customer->id,
            'invoice_date' => $invoiceDate, 'due_date' => $invoiceDate, 'subtotal' => $amount, 'discount_amount' => 0,
            'tax_amount' => 0, 'grand_total' => $amount,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id, 'item_id' => $item->id, 'item_code' => $item->item_code,
            'item_name' => $item->item_name, 'uom' => 'PCS', 'rate' => $rate, 'qty' => $qty, 'amount' => $amount, 'tax_amount' => 0,
        ]);
    }

    public function test_list_aggregates_multiple_invoice_lines_of_the_same_item_into_one_row(): void
    {
        $item = $this->makeItem('ITM-1', 'Widget');
        $this->invoicedLine($item, 10, 1000);
        $this->invoicedLine($item, 5, 1000);

        $response = $this->get('/api/v1/reports/sales/products')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(15, $rows[0]['qty']);
        $this->assertEquals(15000, $rows[0]['amount']);
    }

    public function test_kpi_totals_reflect_the_full_filtered_set_not_just_the_loaded_page(): void
    {
        // 3 distinct items, per_page=1 so only one row would ever appear on "the loaded page" —
        // this is the exact bug class SalesReportPage.tsx's old client-side sum had.
        $this->invoicedLine($this->makeItem('ITM-1', 'Widget A'), 10, 1000);
        $this->invoicedLine($this->makeItem('ITM-2', 'Widget B'), 5, 2000);
        $this->invoicedLine($this->makeItem('ITM-3', 'Widget C'), 1, 500);

        $response = $this->get('/api/v1/reports/sales/products?per_page=1')->assertOk();

        $this->assertCount(1, $response->json('data')); // per_page=1 really did cap the loaded page to 1 row
        $this->assertEquals(3, $response->json('meta.last_page'));

        $kpis = $response->json('meta.kpis');
        $this->assertEquals(16, $kpis['total_qty']); // 10 + 5 + 1, across all 3 items, not just page 1
        $this->assertEquals(10000 + 10000 + 500, $kpis['total_revenue']);
        $this->assertEquals(3, $kpis['sku_count']);
        $this->assertEquals('Widget A', $kpis['top_item_name']); // 10000, tied with Widget B but inserted first
    }

    public function test_draft_invoices_are_excluded_by_default(): void
    {
        $item = $this->makeItem('ITM-1', 'Widget');
        $this->invoicedLine($item, 10, 1000, status: 'draft');

        $response = $this->get('/api/v1/reports/sales/products')->assertOk();

        $this->assertCount(0, $response->json('data'));
        $this->assertEquals(0, $response->json('meta.kpis.total_qty'));
    }

    public function test_date_range_filter_narrows_the_aggregate(): void
    {
        $item = $this->makeItem('ITM-1', 'Widget');
        $this->invoicedLine($item, 10, 1000, '2026-01-15');
        $this->invoicedLine($item, 5, 1000, '2026-02-15');

        $response = $this->get('/api/v1/reports/sales/products?date_from=2026-01-01&date_to=2026-01-31')->assertOk();

        $this->assertEquals(10, $response->json('data.0.qty'));
        $this->assertEquals(10, $response->json('meta.kpis.total_qty'));
    }

    public function test_item_group_view_groups_rows_by_item_group_with_sku_count(): void
    {
        $this->invoicedLine($this->makeItem('ITM-1', 'Widget A'), 10, 1000);
        $this->invoicedLine($this->makeItem('ITM-2', 'Widget B'), 5, 1000);

        $response = $this->get('/api/v1/reports/sales/products?group=item_group')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_group']);
        $this->assertEquals('Hardware', $rows[0]['item_name']);
        $this->assertEquals(2, $rows[0]['sku_count']);
        $this->assertEquals(15, $rows[0]['qty']);
    }

    public function test_customers_drilldown_lists_who_bought_the_item(): void
    {
        $item = $this->makeItem('ITM-1', 'Widget');
        $this->invoicedLine($item, 10, 1000);
        $other = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Other Co']);
        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $other->id,
            'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'subtotal' => 5000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 5000,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id, 'item_id' => $item->id, 'item_code' => $item->item_code,
            'item_name' => $item->item_name, 'uom' => 'PCS', 'rate' => 1000, 'qty' => 5, 'amount' => 5000, 'tax_amount' => 0,
        ]);

        $response = $this->get("/api/v1/reports/sales/products/{$item->id}/customers")->assertOk();

        $customers = collect($response->json('data'))->pluck('customer_name');
        $this->assertEqualsCanonicalizing(['Acme', 'Other Co'], $customers->all());
    }
}
