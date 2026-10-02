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
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Reports > Inventory Stock > Balance tab's Print button — see StockLedgerExportService::balanceReportRows(). */
class StockBalancePrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'reports.inventory_stock.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.inventory_stock.view');
        Sanctum::actingAs($user);
    }

    public function test_print_report_shows_opening_in_out_and_closing_balance_per_item_and_location(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'Semen']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);

        $service = app(StockLedgerService::class);
        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::OPENING_STOCK,
            voucherId: (string) Str::uuid(), qtyChange: 50, postingDatetime: now()->subDays(10),
        );
        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::GOODS_RECEIPT,
            voucherId: (string) Str::uuid(), qtyChange: 30, postingDatetime: now()->subDays(3),
        );
        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::OUT, voucherType: StockVoucherType::DELIVERY,
            voucherId: (string) Str::uuid(), qtyChange: -20, postingDatetime: now()->subDays(1),
        );

        $dateFrom = now()->subDays(5)->toDateString();
        $dateTo = now()->toDateString();

        $response = $this->getJson("/api/v1/stock-ledger/balances/print?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];
        $this->assertSame('ITM001', $row['item_code']);
        $this->assertSame('SMD', $row['location_code']);
        $this->assertSame('Semen', $row['item_group_name']);
        $this->assertEquals(50, $row['bf']);
        $this->assertEquals(30, $row['in']);
        $this->assertEquals(20, $row['out']);
        $this->assertEquals(60, $row['balance']);

        $this->assertEquals(50, $data['totals']['bf']);
        $this->assertEquals(60, $data['totals']['balance']);
    }

    public function test_search_filters_rows_and_totals_by_item_code_or_name(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'Semen']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $itemA = Item::query()->create(['item_code' => 'SEMEN-A', 'item_name' => 'Semen A', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000]);
        $itemB = Item::query()->create(['item_code' => 'PAKU-B', 'item_name' => 'Paku B', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000]);

        $service = app(StockLedgerService::class);
        foreach ([$itemA, $itemB] as $item) {
            $service->record(
                itemId: $item->id, warehouseId: $warehouse->id,
                transactionType: StockTransactionType::IN, voucherType: StockVoucherType::OPENING_STOCK,
                voucherId: (string) Str::uuid(), qtyChange: 10, postingDatetime: now()->subDays(1),
            );
        }

        $response = $this->getJson('/api/v1/stock-ledger/balances/print?search=semen');

        $response->assertOk();
        $rows = $response->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertSame('SEMEN-A', $rows[0]['item_code']);
    }
}
