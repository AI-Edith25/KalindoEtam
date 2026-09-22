<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FifoLayerService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reproduces a real prod bug: Stock Balance's Current Qty showed 0 for an
 * item/warehouse whose Total Value and Valuation-tab Closing Qty were both
 * non-zero and correct. Root cause: a mistaken Opening Stock (IN) reversed
 * same-day via cancel() — which posts its OUT at now() (wall-clock time),
 * not the document's cutoff_date (midnight) — followed by the *correct*
 * Opening Stock posted at the same cutoff_date. Because the correction's
 * posting_datetime (midnight) sorts *before* the same-day cancellation's
 * posting_datetime (a later time of day), picking "the single latest row
 * by posting_datetime" (the old currentBalances() logic) surfaced the
 * cancellation's balance_qty=0 instead of the correction's true balance.
 */
class StockBalanceCurrentQtyTest extends TestCase
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

    public function test_current_qty_is_the_net_sum_not_the_latest_by_timestamp_row(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'SC-PCC-50', 'item_name' => 'SC PCC 50 KG', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 0,
        ]);

        $cutoffDate = Carbon::parse('2026-01-01 00:00:00');
        $sameDayLaterTime = Carbon::parse('2026-01-01 14:23:00');

        $ledger = app(StockLedgerService::class);
        $fifo = app(FifoLayerService::class);

        // Mistaken Opening Stock: IN 104,678 at midnight cutoff.
        $ledger->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::OPENING_STOCK,
            voucherId: (string) Str::uuid(), qtyChange: 104678, postingDatetime: $cutoffDate,
        );

        // Cancelled same day, but cancel() posts at now() — a later time of day, not the cutoff.
        $ledger->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::OUT, voucherType: StockVoucherType::OPENING_STOCK,
            voucherId: (string) Str::uuid(), qtyChange: -104678, postingDatetime: $sameDayLaterTime,
        );

        // Correct Opening Stock, posted at the same midnight cutoff_date (submitted after, but its
        // timestamp is earlier than the same-day cancellation above).
        $ledger->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::OPENING_STOCK,
            voucherId: (string) Str::uuid(), qtyChange: 2286, postingDatetime: $cutoffDate,
        );
        $fifo->receive($item->id, $warehouse->id, 2286, 31532, StockVoucherType::OPENING_STOCK, (string) Str::uuid(), 'OS00023', $cutoffDate);

        $response = $this->getJson('/api/v1/stock-ledger/balances/report');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('item_id', $item->id);

        $this->assertNotNull($row, 'Item/warehouse pair should still appear in the Balance report.');
        $this->assertEquals(2286, $row['current_qty']);
        $this->assertEquals(2286 * 31532, $row['total_value']);
    }
}
