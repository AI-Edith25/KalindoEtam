<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\FifoLayer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\ReceiptStockService;
use App\Services\StockLedgerService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptStockTest extends TestCase
{
    use RefreshDatabase;

    protected ReceiptStockService $receiptStockService;

    protected StockLedgerService $stockLedgerService;

    protected Warehouse $warehouse;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->receiptStockService = app(ReceiptStockService::class);
        $this->stockLedgerService = app(StockLedgerService::class);

        $this->warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);
    }

    public function test_submit_creates_a_new_layer_at_the_user_entered_cost_and_moves_the_ledger(): void
    {
        $receiptStock = $this->receiptStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 40, 'unit_cost' => 62000]],
        ]);
        $receiptStock = $this->receiptStockService->submit($receiptStock->fresh(['items']));

        $this->assertSame('submitted', $receiptStock->status->value);
        $this->assertEquals(40, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));

        $layer = FifoLayer::query()->where('source_id', $receiptStock->id)->sole();
        $this->assertEquals(40, (float) $layer->qty_remaining);
        $this->assertEquals(62000, (float) $layer->unit_cost);
    }

    public function test_cancel_reverses_an_unconsumed_receipt_stock(): void
    {
        $receiptStock = $this->receiptStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 40, 'unit_cost' => 62000]],
        ]);
        $receiptStock = $this->receiptStockService->submit($receiptStock->fresh(['items']));

        $cancelled = $this->receiptStockService->cancel($receiptStock);

        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertEquals(0, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
        $this->assertSame(0, FifoLayer::query()->where('source_id', $receiptStock->id)->count());
    }

    public function test_cancel_is_rejected_once_the_layer_has_been_partially_consumed(): void
    {
        $receiptStock = $this->receiptStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 40, 'unit_cost' => 62000]],
        ]);
        $receiptStock = $this->receiptStockService->submit($receiptStock->fresh(['items']));

        app(\App\Services\FifoLayerService::class)->consume(
            $this->item->id, $this->warehouse->id, 10, \App\Enums\StockVoucherType::DELIVERY, (string) \Illuminate\Support\Str::uuid(),
        );

        $this->expectException(BusinessException::class);
        $this->receiptStockService->cancel($receiptStock);
    }

    public function test_negative_unit_cost_is_rejected(): void
    {
        $this->expectException(BusinessException::class);

        $this->receiptStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'unit_cost' => -1]],
        ]);
    }

    /** Smoke test for the route/permission/FormRequest wiring — same convention as OpeningStockTest. */
    public function test_http_create_submit_and_cancel_round_trip(): void
    {
        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.receipt_stock.create', 'guard_name' => 'web']);
        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.receipt_stock.update', 'guard_name' => 'web']);
        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.receipt_stock.view', 'guard_name' => 'web']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo(['inventory.receipt_stock.create', 'inventory.receipt_stock.update', 'inventory.receipt_stock.view']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/receipt-stocks', [
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'unit_cost' => 62000]],
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        $submit = $this->postJson("/api/v1/receipt-stocks/{$id}/submit");
        $submit->assertOk();
        $this->assertSame('submitted', $submit->json('data.status'));

        $cancel = $this->postJson("/api/v1/receipt-stocks/{$id}/cancel");
        $cancel->assertOk();
        $this->assertSame('cancelled', $cancel->json('data.status'));
    }
}
