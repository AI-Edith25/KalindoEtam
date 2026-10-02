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

/** Reports > Inventory Stock > Ledger tab's Print button — see StockLedgerExportService::summaryStructureForPrint(). */
class StockLedgerSummaryPrintTest extends TestCase
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

    public function test_print_structure_nests_location_item_group_and_item_with_running_balance(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
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

        $response = $this->getJson("/api/v1/stock-ledger/summary/print?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data['locations']);
        $location = $data['locations'][0];
        $this->assertSame('Samarinda', $location['name']);
        $this->assertSame('SMD', $location['code']);

        $this->assertCount(1, $location['itemGroups']);
        $itemGroupOut = $location['itemGroups'][0];
        $this->assertSame('General', $itemGroupOut['name']);

        $itemOut = $itemGroupOut['items'][0];
        $this->assertSame('ITM001', $itemOut['code']);
        $this->assertEquals(50, $itemOut['openingQty']);
        $this->assertCount(2, $itemOut['txnRows']);
        $this->assertEquals(30, $itemOut['txnRows'][0]['qty_in']);
        $this->assertEquals(80, $itemOut['txnRows'][0]['balance_qty']);
        $this->assertEquals(20, $itemOut['txnRows'][1]['qty_out']);
        $this->assertEquals(60, $itemOut['txnRows'][1]['balance_qty']);
        $this->assertEquals(60, $itemOut['closingQty']);

        $this->assertEquals(60, $data['grandTotals']['closingQty']);
    }

    public function test_delivery_transactions_carry_the_invoice_reference_other_voucher_types_leave_blank(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM002', 'item_name' => 'Besi 10mm', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 15000,
        ]);

        $service = app(StockLedgerService::class);
        $dateFrom = now()->subDays(3)->toDateString();
        $dateTo = now()->toDateString();

        // A plain Goods Receipt voucher — no invoice to derive, invoice_reference must stay null.
        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::GOODS_RECEIPT,
            voucherId: (string) Str::uuid(), qtyChange: 40, postingDatetime: now()->subDays(2),
        );

        $response = $this->getJson("/api/v1/stock-ledger/summary/print?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertOk();
        $itemOut = $response->json('data.locations.0.itemGroups.0.items.0');
        $this->assertNull($itemOut['txnRows'][0]['invoice_reference']);
    }
}
