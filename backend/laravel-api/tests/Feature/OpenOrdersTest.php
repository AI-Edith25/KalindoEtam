<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Open Orders tab — one row per Sales Order line still outstanding (qty_ordered > qty_invoiced). */
class OpenOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $itemGroup = ItemGroup::query()->create(['name' => 'Hardware']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000]);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    /** One SO line, optionally delivered/invoiced against — a real SalesOrderItem->DeliveryItem->InvoiceItem chain, direct Eloquent creates (only the report path is under test). */
    protected function makeLine(int $qtyOrdered, float $rate, int $qtyDelivered = 0, int $qtyInvoiced = 0, array $soOverrides = []): SalesOrderItem
    {
        $salesOrder = SalesOrder::query()->create(array_merge([
            'customer_id' => $this->customer->id,
            'status' => 'approved',
            'order_date' => now()->toDateString(),
        ], $soOverrides));

        $soItem = SalesOrderItem::query()->create([
            'sales_order_id' => $salesOrder->id, 'item_id' => $this->item->id,
            'qty' => $qtyOrdered, 'rate' => $rate, 'amount' => $qtyOrdered * $rate, 'delivered_qty' => $qtyDelivered,
        ]);

        if ($qtyInvoiced > 0) {
            $warehouse = Warehouse::query()->firstOrCreate(['code' => 'WH1'], ['name' => 'Main WH', 'warehouse_type' => 'main']);
            $delivery = Delivery::query()->create([
                'sales_order_id' => $salesOrder->id, 'customer_id' => $this->customer->id, 'warehouse_id' => $warehouse->id,
                'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'status' => 'complete',
            ]);
            $deliveryItem = DeliveryItem::query()->create([
                'delivery_id' => $delivery->id, 'sales_order_item_id' => $soItem->id, 'item_id' => $this->item->id,
                'item_code' => $this->item->item_code, 'item_name' => $this->item->item_name, 'uom' => 'PCS',
                'rate' => $rate, 'qty' => $qtyInvoiced, 'amount' => $qtyInvoiced * $rate,
            ]);
            $invoice = Invoice::query()->create([
                'invoice_type' => 'goods', 'status' => 'submitted', 'delivery_id' => $delivery->id, 'sales_order_id' => $salesOrder->id,
                'customer_id' => $this->customer->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
                'subtotal' => $qtyInvoiced * $rate, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $qtyInvoiced * $rate,
            ]);
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id, 'delivery_item_id' => $deliveryItem->id, 'item_id' => $this->item->id,
                'item_code' => $this->item->item_code, 'item_name' => $this->item->item_name, 'uom' => 'PCS',
                'rate' => $rate, 'qty' => $qtyInvoiced, 'amount' => $qtyInvoiced * $rate, 'tax_amount' => 0,
            ]);
        }

        return $soItem;
    }

    public function test_undelivered_uninvoiced_line_appears_fully_outstanding(): void
    {
        $this->makeLine(10, 1000);

        $response = $this->get('/api/v1/reports/sales/open-orders')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(10, $rows[0]['qty_ordered']);
        $this->assertEquals(0, $rows[0]['qty_delivered']);
        $this->assertEquals(0, $rows[0]['qty_invoiced']);
        $this->assertEquals(10, $rows[0]['qty_outstanding']);
        $this->assertEquals(10000, $rows[0]['outstanding_value']);
        $this->assertEquals('not_delivered', $rows[0]['delivery_status']);
        $this->assertEquals('not_invoiced', $rows[0]['invoice_status']);
    }

    public function test_partially_delivered_and_invoiced_line_shows_the_remaining_balance(): void
    {
        $this->makeLine(10, 1000, qtyDelivered: 6, qtyInvoiced: 4);

        $response = $this->get('/api/v1/reports/sales/open-orders')->assertOk();

        $row = $response->json('data.0');
        $this->assertEquals(6, $row['qty_outstanding']); // 10 ordered - 4 invoiced, NOT 10 - 6 delivered
        $this->assertEquals('partially_delivered', $row['delivery_status']);
        $this->assertEquals('partially_invoiced', $row['invoice_status']);
    }

    public function test_fully_invoiced_line_is_excluded_even_if_more_was_delivered_than_invoiced(): void
    {
        $this->makeLine(10, 1000, qtyDelivered: 10, qtyInvoiced: 10);

        $response = $this->get('/api/v1/reports/sales/open-orders')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_cancelled_sales_order_is_always_excluded(): void
    {
        $this->makeLine(10, 1000, soOverrides: ['status' => 'cancelled']);

        $response = $this->get('/api/v1/reports/sales/open-orders')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_kpi_totals_reflect_the_full_filtered_set_not_just_the_loaded_page(): void
    {
        $this->makeLine(10, 1000); // 10000 outstanding
        $this->makeLine(5, 2000); // 10000 outstanding
        $this->makeLine(1, 500); // 500 outstanding

        $response = $this->get('/api/v1/reports/sales/open-orders?per_page=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(3, $response->json('meta.last_page'));

        $kpis = $response->json('meta.kpis');
        $this->assertEquals(20500, $kpis['total_outstanding_value']);
        $this->assertEquals(3, $kpis['open_so_count']);
    }

    public function test_overdue_flag_and_value_reflect_expected_delivery_date_in_the_past(): void
    {
        $this->makeLine(10, 1000, soOverrides: ['expected_delivery_date' => now()->subDays(5)->toDateString()]); // overdue
        $this->makeLine(5, 1000, soOverrides: ['expected_delivery_date' => now()->addDays(5)->toDateString()]); // not yet due

        $response = $this->get('/api/v1/reports/sales/open-orders?sort=outstanding_value&sort_dir=desc')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->firstWhere('qty_ordered', 10)['is_overdue']);
        $this->assertFalse($rows->firstWhere('qty_ordered', 5)['is_overdue']);

        $this->assertEquals(10000, $response->json('meta.kpis.overdue_value'));
    }

    public function test_overdue_only_filter_narrows_to_overdue_lines(): void
    {
        $this->makeLine(10, 1000, soOverrides: ['expected_delivery_date' => now()->subDays(5)->toDateString()]);
        $this->makeLine(5, 1000, soOverrides: ['expected_delivery_date' => now()->addDays(5)->toDateString()]);

        $response = $this->get('/api/v1/reports/sales/open-orders?overdue_only=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_aging_bucket_filter_narrows_by_order_date(): void
    {
        $this->makeLine(10, 1000, soOverrides: ['order_date' => now()->subDays(2)->toDateString()]); // 0-7
        $this->makeLine(5, 1000, soOverrides: ['order_date' => now()->subDays(45)->toDateString()]); // 31-60

        $response = $this->get('/api/v1/reports/sales/open-orders?aging=0-7')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(10, $response->json('data.0.qty_ordered'));
    }
}
