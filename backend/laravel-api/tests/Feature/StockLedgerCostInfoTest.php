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

/** Stock Ledger gained Unit Cost/Value In/Value Out/Balance Value columns (Stage e). */
class StockLedgerCostInfoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'inventory.stock_ledger.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.stock_ledger.view');
        Sanctum::actingAs($user);
    }

    public function test_in_and_out_rows_carry_exact_cost_from_the_fifo_audit_trail(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);

        $fifo = app(FifoLayerService::class);
        $receiptId = (string) Str::uuid();
        $fifo->receive($item->id, $warehouse->id, 30, 58000, StockVoucherType::GOODS_RECEIPT, $receiptId, 'GR-1', now());

        app(\App\Services\StockLedgerService::class)->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: \App\Enums\StockTransactionType::IN, voucherType: StockVoucherType::GOODS_RECEIPT,
            voucherId: $receiptId, qtyChange: 30, postingDatetime: now(),
        );

        $deliveryId = (string) Str::uuid();
        $fifo->consume($item->id, $warehouse->id, 10, StockVoucherType::DELIVERY, $deliveryId);
        app(\App\Services\StockLedgerService::class)->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: \App\Enums\StockTransactionType::OUT, voucherType: StockVoucherType::DELIVERY,
            voucherId: $deliveryId, qtyChange: -10, postingDatetime: now(),
        );

        $response = $this->getJson('/api/v1/stock-ledger');
        $response->assertOk();

        $rows = collect($response->json('data'));
        $inRow = $rows->firstWhere('voucher_id', $receiptId);
        $outRow = $rows->firstWhere('voucher_id', $deliveryId);

        $this->assertEquals(58000, $inRow['unit_cost']);
        $this->assertEquals(30 * 58000, $inRow['value_in']);
        $this->assertEquals(0, $inRow['value_out']);

        $this->assertEquals(58000, $outRow['unit_cost']);
        $this->assertEquals(10 * 58000, $outRow['value_out']);
        $this->assertEquals(0, $outRow['value_in']);

        // Balance after both: 20 remaining @ 58000 (only one cost in the layer set).
        $this->assertEquals(20 * 58000, $outRow['balance_value']);
    }
}
