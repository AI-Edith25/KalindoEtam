<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Enums\WarehouseType;
use App\Models\FifoLayer;
use App\Models\FifoLayerConsumption;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\IssueStockService;
use App\Services\StockLedgerService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueStockTest extends TestCase
{
    use RefreshDatabase;

    protected IssueStockService $issueStockService;

    protected StockLedgerService $stockLedgerService;

    protected Warehouse $warehouse;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->issueStockService = app(IssueStockService::class);
        $this->stockLedgerService = app(StockLedgerService::class);

        $this->warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);
    }

    public function test_submit_consumes_the_oldest_layer_first_and_moves_the_ledger(): void
    {
        $this->seedStock($this->item->id, $this->warehouse->id, 100, unitCost: 58000);
        $this->seedStock($this->item->id, $this->warehouse->id, 50, unitCost: 65000);

        $issueStock = $this->issueStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'issue_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 30]],
        ]);
        $issueStock = $this->issueStockService->submit($issueStock->fresh(['items']));

        $this->assertSame('submitted', $issueStock->status->value);
        $this->assertEquals(120, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));

        // Oldest (58000) layer consumed first, not the newer 65000 one.
        $this->assertEquals(58000, (float) $issueStock->items->first()->unit_cost);
        $this->assertEquals(58000 * 30, (float) $issueStock->items->first()->amount);

        $oldestLayer = FifoLayer::query()->where('unit_cost', 58000)->sole();
        $this->assertEquals(70, (float) $oldestLayer->qty_remaining);
    }

    public function test_submit_splits_across_layers_when_the_oldest_is_not_enough(): void
    {
        $this->seedStock($this->item->id, $this->warehouse->id, 20, unitCost: 58000);
        $this->seedStock($this->item->id, $this->warehouse->id, 50, unitCost: 65000);

        $issueStock = $this->issueStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'issue_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 30]],
        ]);
        $issueStock = $this->issueStockService->submit($issueStock->fresh(['items']));

        // 20 @ 58000 + 10 @ 65000 = 1,160,000+650,000 = 1,810,000 / 30 = 60,333.33 -> rounds to 60333.33
        $this->assertEqualsWithDelta(60333.33, (float) $issueStock->items->first()->unit_cost, 0.01);
    }

    public function test_submit_is_rejected_when_qty_exceeds_available_stock(): void
    {
        $this->seedStock($this->item->id, $this->warehouse->id, 20, unitCost: 58000);

        $issueStock = $this->issueStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'issue_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 50]],
        ]);

        try {
            $this->issueStockService->submit($issueStock->fresh(['items']));
            $this->fail('Expected a BusinessException for over-limit qty.');
        } catch (BusinessException $e) {
            // Rejected inside the transaction — nothing should have moved.
            $this->assertEquals(20, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
            $this->assertSame('draft', $issueStock->fresh()->status->value);
        }
    }

    public function test_cancel_restores_the_exact_original_layers(): void
    {
        $this->seedStock($this->item->id, $this->warehouse->id, 20, unitCost: 58000);
        $this->seedStock($this->item->id, $this->warehouse->id, 50, unitCost: 65000);

        $issueStock = $this->issueStockService->create([
            'warehouse_id' => $this->warehouse->id,
            'issue_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 30]],
        ]);
        $issueStock = $this->issueStockService->submit($issueStock->fresh(['items']));

        $this->issueStockService->cancel($issueStock);

        $this->assertEquals(70, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
        $oldestLayer = FifoLayer::query()->where('unit_cost', 58000)->sole();
        $this->assertEquals(20, (float) $oldestLayer->qty_remaining);
        $this->assertTrue(FifoLayerConsumption::query()->where('fifo_layer_id', $oldestLayer->id)->sole()->reversed);
    }

    public function test_preview_cost_is_read_only_and_matches_what_submit_would_charge(): void
    {
        $this->seedStock($this->item->id, $this->warehouse->id, 20, unitCost: 58000);
        $this->seedStock($this->item->id, $this->warehouse->id, 50, unitCost: 65000);

        $preview = $this->issueStockService->previewCost($this->item->id, $this->warehouse->id, 30);

        $this->assertEqualsWithDelta(60333.33, $preview['unit_cost'], 0.01);
        $this->assertEquals(70, $preview['available_qty']);
        // Nothing was written — a second identical preview call gives the same answer.
        $this->assertEquals(70, FifoLayer::query()->sum('qty_remaining'));
    }

    /** Smoke test for the route/permission/FormRequest wiring — same convention as OpeningStockTest. */
    public function test_http_create_submit_and_cancel_round_trip(): void
    {
        $this->seedStock($this->item->id, $this->warehouse->id, 20, unitCost: 58000);

        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.issue_stock.create', 'guard_name' => 'web']);
        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.issue_stock.update', 'guard_name' => 'web']);
        \App\Models\Permission::query()->firstOrCreate(['name' => 'inventory.issue_stock.view', 'guard_name' => 'web']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo(['inventory.issue_stock.create', 'inventory.issue_stock.update', 'inventory.issue_stock.view']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/issue-stocks', [
            'warehouse_id' => $this->warehouse->id,
            'issue_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5]],
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        $submit = $this->postJson("/api/v1/issue-stocks/{$id}/submit");
        $submit->assertOk();
        $this->assertSame('submitted', $submit->json('data.status'));

        $cancel = $this->postJson("/api/v1/issue-stocks/{$id}/cancel");
        $cancel->assertOk();
        $this->assertSame('cancelled', $cancel->json('data.status'));
    }
}
