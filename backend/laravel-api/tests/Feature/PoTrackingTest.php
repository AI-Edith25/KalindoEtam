<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Purchase Report's PO Tracking tab — submitted Purchase Orders whose Goods Receipts haven't fully arrived yet. */
class PoTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;

    protected GoodsReceiptService $goodsReceiptService;

    protected Supplier $supplier;

    protected Warehouse $warehouse;

    protected Item $item;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);

        Permission::query()->firstOrCreate(['name' => 'reports.purchase.view', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('reports.purchase.view');
        Sanctum::actingAs($this->user);
    }

    protected function submittedPurchaseOrder(int|float $qty, float $rate, ?string $expectedDeliveryDate = null): PurchaseOrder
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => $expectedDeliveryDate,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($purchaseOrder);
        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder);
        Sanctum::actingAs($this->user); // approveDocument() above swapped the acting user to a throwaway approver

        return $purchaseOrder;
    }

    protected function receiveAgainst(PurchaseOrder $purchaseOrder, int|float $qty): void
    {
        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $this->goodsReceiptService->submit($goodsReceipt);
    }

    public function test_received_qty_sums_across_three_separate_goods_receipts(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 30, rate: 10000);

        $this->receiveAgainst($purchaseOrder, 10);
        $this->receiveAgainst($purchaseOrder, 12);
        $this->receiveAgainst($purchaseOrder, 8);

        $row = $this->get('/api/v1/reports/purchase/po-tracking?incomplete_only=0')->assertOk()->json('data.0');

        $this->assertEquals(30, $row['ordered_qty']);
        $this->assertEquals(30, $row['received_qty']); // sum of all three, not just the last
        $this->assertEquals(0, $row['remaining_qty']);
        $this->assertEquals(100.0, $row['fulfillment_pct']);
        $this->assertEquals('complete', $row['receiving_status']);
    }

    public function test_partial_receipt_shows_sebagian_status_and_is_excluded_by_default_toggle(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 30, rate: 10000);
        $this->receiveAgainst($purchaseOrder, 10);

        // Default "Hanya yang belum lengkap" behavior — no query param sent, backend defaults to false unless the frontend sends true; this asserts the raw partial data shape.
        $row = $this->get('/api/v1/reports/purchase/po-tracking')->assertOk()->json('data.0');

        $this->assertEquals(10, $row['received_qty']);
        $this->assertEquals(20, $row['remaining_qty']);
        $this->assertEqualsWithDelta(33.33, $row['fulfillment_pct'], 0.01);
        $this->assertEquals('partial', $row['receiving_status']);
    }

    /**
     * axios serializes a JS boolean query param as the literal string "true"/"false" — Laravel's
     * `boolean` validation rule rejects that (strict in_array against true/false/0/1/"0"/"1"),
     * which 500'd/422'd this endpoint in production even though every local test used "0"/"1".
     * See IndexPoTrackingRequest and PoTrackingRepository::filteredQuery()'s filter_var() cast.
     */
    public function test_incomplete_only_accepts_the_string_forms_axios_actually_sends(): void
    {
        $complete = $this->submittedPurchaseOrder(qty: 10, rate: 10000);
        $this->receiveAgainst($complete, 10);
        $partial = $this->submittedPurchaseOrder(qty: 10, rate: 10000);
        $this->receiveAgainst($partial, 4);

        $this->get('/api/v1/reports/purchase/po-tracking?incomplete_only=true')->assertOk();
        $rowsTrue = $this->get('/api/v1/reports/purchase/po-tracking?incomplete_only=true')->assertOk()->json('data');
        $this->assertCount(1, $rowsTrue);
        $this->assertEquals($partial->id, $rowsTrue[0]['id']);

        $rowsFalse = $this->get('/api/v1/reports/purchase/po-tracking?incomplete_only=false')->assertOk()->json('data');
        $this->assertCount(2, $rowsFalse); // "false" must not be misread as truthy
    }

    public function test_not_received_status_when_nothing_has_arrived(): void
    {
        $this->submittedPurchaseOrder(qty: 30, rate: 10000);

        $row = $this->get('/api/v1/reports/purchase/po-tracking')->assertOk()->json('data.0');

        $this->assertEquals(0, $row['received_qty']);
        $this->assertEquals(0.0, $row['fulfillment_pct']);
        $this->assertEquals('not_received', $row['receiving_status']);
    }

    public function test_incomplete_only_toggle_hides_fully_received_pos(): void
    {
        $complete = $this->submittedPurchaseOrder(qty: 10, rate: 10000);
        $this->receiveAgainst($complete, 10);
        $this->submittedPurchaseOrder(qty: 10, rate: 10000); // stays not_received

        $rows = $this->get('/api/v1/reports/purchase/po-tracking?incomplete_only=1')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertNotEquals('complete', $rows[0]['receiving_status']);
    }

    public function test_receiving_status_filter(): void
    {
        $this->submittedPurchaseOrder(qty: 10, rate: 10000); // not_received

        $rows = $this->get('/api/v1/reports/purchase/po-tracking?receiving_status=complete')->assertOk()->json('data');

        $this->assertCount(0, $rows);
    }

    public function test_direct_receipt_never_appears_in_po_tracking(): void
    {
        $this->goodsReceiptService->submit($this->goodsReceiptService->create([
            'purchase_order_id' => null,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000]],
        ]));

        $rows = $this->get('/api/v1/reports/purchase/po-tracking?incomplete_only=0')->assertOk()->json('data');

        $this->assertCount(0, $rows);
    }

    public function test_draft_purchase_order_is_excluded(): void
    {
        $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 10000]],
        ]); // never approved/submitted -> stays Draft

        $rows = $this->get('/api/v1/reports/purchase/po-tracking?incomplete_only=0')->assertOk()->json('data');

        $this->assertCount(0, $rows);
    }

    public function test_overdue_flag_set_only_when_past_expected_delivery_and_incomplete(): void
    {
        $overdue = $this->submittedPurchaseOrder(qty: 10, rate: 10000, expectedDeliveryDate: now()->subDays(5)->toDateString());
        $onTrack = $this->submittedPurchaseOrder(qty: 10, rate: 10000, expectedDeliveryDate: now()->addDays(5)->toDateString());
        $this->receiveAgainst($onTrack, 10); // complete, would be overdue-by-date but fully received

        $rows = collect($this->get('/api/v1/reports/purchase/po-tracking?incomplete_only=0')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$overdue->id]['is_overdue']);
        $this->assertFalse($rows[$onTrack->id]['is_overdue']);
    }

    public function test_items_drilldown_returns_ordered_received_and_remaining_per_line(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 10, rate: 10000);
        $this->receiveAgainst($purchaseOrder, 4);

        $items = $this->get("/api/v1/reports/purchase/po-tracking/{$purchaseOrder->id}/items")->assertOk()->json('data');

        $this->assertCount(1, $items);
        $this->assertEquals(10, $items[0]['ordered_qty']);
        $this->assertEquals(4, $items[0]['received_qty']);
        $this->assertEquals(6, $items[0]['remaining_qty']);
    }
}
