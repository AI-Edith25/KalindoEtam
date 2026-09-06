<?php

namespace Tests\Feature;

use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FifoLayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Stock Balance report gained Total Value/Avg Cost columns (Stage e) — sourced from FifoLayer, not a second cost concept. */
class StockBalanceValueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'inventory.stock_balance.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.stock_balance.view');
        Sanctum::actingAs($user);
    }

    public function test_total_value_and_avg_cost_are_computed_from_fifo_layers(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);

        $fifo = app(FifoLayerService::class);
        $fifo->receive($item->id, $warehouse->id, 30, 58000, StockVoucherType::GOODS_RECEIPT, (string) Str::uuid(), 'GR-1', now());
        $fifo->receive($item->id, $warehouse->id, 20, 60000, StockVoucherType::GOODS_RECEIPT, (string) Str::uuid(), 'GR-2', now());

        app(\App\Services\StockLedgerService::class)->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: \App\Enums\StockTransactionType::IN, voucherType: StockVoucherType::GOODS_RECEIPT,
            voucherId: (string) Str::uuid(), qtyChange: 50, postingDatetime: now(),
        );

        $response = $this->getJson('/api/v1/stock-ledger/balances/report');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('item_id', $item->id);
        $this->assertEquals(30 * 58000 + 20 * 60000, $row['total_value']);
        $this->assertEqualsWithDelta((30 * 58000 + 20 * 60000) / 50, $row['avg_cost'], 0.01);

        $summary = $response->json('meta.summary');
        $this->assertEquals(30 * 58000 + 20 * 60000, $summary['total_value']);
        $this->assertEquals(1, $summary['item_count']);
    }
}
