<?php

namespace Tests\Feature;

use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\FifoLayer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\OpeningStockService;
use App\Services\StockLedgerService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningStockTest extends TestCase
{
    use RefreshDatabase;

    protected OpeningStockService $openingStockService;

    protected StockLedgerService $stockLedgerService;

    protected Warehouse $warehouse;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->openingStockService = app(OpeningStockService::class);
        $this->stockLedgerService = app(StockLedgerService::class);

        $this->warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);
    }

    public function test_create_allows_multiple_lines_for_the_same_item_at_different_costs(): void
    {
        $openingStock = $this->openingStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'cutoff_date' => now()->subMonth()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 100, 'unit_cost' => 58000],
                ['item_id' => $this->item->id, 'qty' => 50, 'unit_cost' => 60000],
            ],
        ]);

        $this->assertCount(2, $openingStock->items);
    }

    public function test_submit_creates_one_fifo_layer_per_line_and_moves_the_ledger(): void
    {
        $cutoff = now()->subMonth()->toDateString();
        $openingStock = $this->openingStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'cutoff_date' => $cutoff,
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 100, 'unit_cost' => 58000],
                ['item_id' => $this->item->id, 'qty' => 50, 'unit_cost' => 60000],
            ],
        ]);

        $openingStock = $this->openingStockService->submit($openingStock->fresh(['items']));

        $this->assertSame('submitted', $openingStock->status->value);
        $this->assertEquals(150, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));

        $layers = FifoLayer::query()->where('source_id', $openingStock->id)->orderBy('unit_cost')->get();
        $this->assertCount(2, $layers);
        $this->assertEquals(100, (float) $layers[0]->qty_remaining);
        $this->assertEquals(58000, (float) $layers[0]->unit_cost);
        $this->assertEquals($cutoff, $layers[0]->received_date->toDateString());
    }

    public function test_cancel_reverses_an_unconsumed_opening_stock(): void
    {
        $openingStock = $this->openingStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'cutoff_date' => now()->subMonth()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 100, 'unit_cost' => 58000]],
        ]);
        $openingStock = $this->openingStockService->submit($openingStock->fresh(['items']));

        $cancelled = $this->openingStockService->cancel($openingStock);

        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertEquals(0, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
        $this->assertSame(0, FifoLayer::query()->where('source_id', $openingStock->id)->count());
    }

    public function test_cancel_is_rejected_once_the_layer_has_been_partially_consumed(): void
    {
        $openingStock = $this->openingStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'cutoff_date' => now()->subMonth()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 100, 'unit_cost' => 58000]],
        ]);
        $openingStock = $this->openingStockService->submit($openingStock->fresh(['items']));

        app(\App\Services\FifoLayerService::class)->consume(
            $this->item->id, $this->warehouse->id, 10, StockVoucherType::DELIVERY, (string) \Illuminate\Support\Str::uuid(),
        );

        $this->expectException(BusinessException::class);
        $this->openingStockService->cancel($openingStock);
    }

    public function test_cutoff_date_after_existing_activity_is_rejected(): void
    {
        $this->seedStock($this->item->id, $this->warehouse->id, 20, unitCost: 55000);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('corrupt FIFO ordering');

        $this->openingStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'cutoff_date' => now()->addDay()->toDateString(), // after the seeded stock's received_date (today, via seedStock)
            'items' => [['item_id' => $this->item->id, 'qty' => 100, 'unit_cost' => 58000]],
        ]);
    }

    public function test_unit_category_item_rejects_decimal_qty(): void
    {
        $this->expectException(BusinessException::class);

        $this->openingStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'cutoff_date' => now()->subMonth()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 12.5, 'unit_cost' => 58000]],
        ]);
    }

    /** Smoke test for the route/permission/FormRequest wiring — everything else in this file goes through the service directly, same convention as StockAdjustmentTest. */
    public function test_http_create_submit_and_cancel_round_trip(): void
    {
        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.opening_stock.create', 'guard_name' => 'web']);
        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.opening_stock.update', 'guard_name' => 'web']);
        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.opening_stock.view', 'guard_name' => 'web']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo(['inventory.opening_stock.create', 'inventory.opening_stock.update', 'inventory.opening_stock.view']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/opening-stocks', [
            'warehouse_id' => $this->warehouse->id,
            'cutoff_date' => now()->subMonth()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'unit_cost' => 58000]],
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        $submit = $this->postJson("/api/v1/opening-stocks/{$id}/submit");
        $submit->assertOk();
        $this->assertSame('submitted', $submit->json('data.status'));

        $cancel = $this->postJson("/api/v1/opening-stocks/{$id}/cancel");
        $cancel->assertOk();
        $this->assertSame('cancelled', $cancel->json('data.status'));
    }
}
