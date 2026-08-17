<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Point 4A — the /sales-orders?outstanding=1 filter surfaces the same predicate the New
 * Delivery flow already uses to pick eligible orders (approved + at least one undelivered
 * item), not just "not cancelled".
 */
class SalesOrderOutstandingFilterTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
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

        Permission::query()->firstOrCreate(['name' => 'sales.orders.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('sales.orders.view');
        Sanctum::actingAs($viewer);
    }

    protected function createOrder(int $qty = 10): SalesOrder
    {
        return $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => 10000]],
        ]);
    }

    protected function deliverPartially(SalesOrder $salesOrder, int $qty): void
    {
        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $this->deliveryService->complete($delivery);
    }

    public function test_approved_partially_delivered_order_appears_under_outstanding(): void
    {
        $partial = $this->createOrder(qty: 10);
        $this->approveDocument($partial);
        $this->salesOrderService->approve($partial);
        $this->deliverPartially($partial, 5);

        $response = $this->getJson('/api/v1/sales-orders?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertTrue($documentNumbers->contains($partial->fresh()->document_number));
    }

    public function test_approved_fully_delivered_order_is_excluded(): void
    {
        $fully = $this->createOrder(qty: 10);
        $this->approveDocument($fully);
        $this->salesOrderService->approve($fully);
        $this->deliverPartially($fully, 10);

        $response = $this->getJson('/api/v1/sales-orders?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertFalse($documentNumbers->contains($fully->fresh()->document_number));
    }

    public function test_cancelled_order_is_excluded(): void
    {
        $cancelled = $this->createOrder(qty: 10);
        $this->salesOrderService->cancel($cancelled);

        $response = $this->getJson('/api/v1/sales-orders?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertFalse($documentNumbers->contains($cancelled->fresh()->document_number));
    }

    /** The corrected predicate: Submitted (not yet approved) must NOT show as Outstanding, even with undelivered items — it isn't deliverable yet, matching the New Delivery flow's approved-only eligible list. */
    public function test_submitted_but_not_approved_order_is_excluded(): void
    {
        $submitted = $this->createOrder(qty: 10);

        $response = $this->getJson('/api/v1/sales-orders?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertFalse($documentNumbers->contains($submitted->fresh()->document_number));
    }

    public function test_outstanding_filter_query_count_does_not_scale_with_row_count(): void
    {
        $makeApprovedPartial = function () {
            $so = $this->createOrder(qty: 10);
            $this->approveDocument($so);
            $this->salesOrderService->approve($so);
            $this->deliverPartially($so, 5);
        };

        $makeApprovedPartial();
        // Warm-up request first — the very first authenticated request in a test process pays a
        // one-time Spatie permission-cache query that has nothing to do with row count; without
        // this warm-up it would look like a false N+1 signal.
        $this->getJson('/api/v1/sales-orders?outstanding=1')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/v1/sales-orders?outstanding=1')->assertOk();
        $queriesForOne = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 4; $i++) {
            $makeApprovedPartial();
        }
        DB::enableQueryLog();
        $this->getJson('/api/v1/sales-orders?outstanding=1')->assertOk();
        $queriesForFive = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesForOne, $queriesForFive, 'Query count must stay flat as row count grows (no N+1).');
    }
}
