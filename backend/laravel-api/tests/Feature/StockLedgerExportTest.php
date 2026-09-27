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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/** Reports > Inventory Stock > Ledger tab's Export button — see StockLedgerExportService's docblock for the Summary/Detail shape. */
class StockLedgerExportTest extends TestCase
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

    protected function downloadWorkbook(string $query = ''): Spreadsheet
    {
        $response = $this->get('/api/v1/stock-ledger/export'.($query !== '' ? "?{$query}" : ''));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'stock-ledger').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tmpPath);
        unlink($tmpPath);

        return $spreadsheet;
    }

    public function test_summary_sheet_opening_balance_and_running_balance_roll_forward_correctly(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);

        $service = app(StockLedgerService::class);

        // Pre-period: +50 — must land in B/F, must NOT count toward in-range QTY IN.
        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::OPENING_STOCK,
            voucherId: (string) Str::uuid(), qtyChange: 50, postingDatetime: now()->subDays(10),
        );

        $dateFrom = now()->subDays(5)->toDateString();
        $dateTo = now()->toDateString();

        // In-range: +30 then -20 — running balance must go 50 -> 80 -> 60.
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

        $spreadsheet = $this->downloadWorkbook("date_from={$dateFrom}&date_to={$dateTo}");
        $summary = $spreadsheet->getSheetByName('Summary');
        $detail = $spreadsheet->getSheetByName('Detail');

        $this->assertNotNull($summary);
        $this->assertNotNull($detail);

        // Single item/group/location in scope, so row positions are deterministic:
        // 7 Location header, 8 Item Group header, 9 Item header, 10 B/F, 11 +30, 12 -20, 13 item
        // subtotal, 14 item-group subtotal, 15 location total, 16 GRAND TOTAL.
        $this->assertEquals('Location: Samarinda', $summary->getCell('A7')->getValue());
        $this->assertStringContainsString('Item Group: General', $summary->getCell('A8')->getValue());
        $this->assertStringContainsString('ITM001', $summary->getCell('A9')->getValue());

        $this->assertEquals('B/F', $summary->getCell('B10')->getValue());
        $this->assertEquals(50, $summary->getCell('J10')->getValue());

        $this->assertEquals(30, $summary->getCell('F11')->getValue());
        $this->assertEquals(80, $summary->getCell('J11')->getValue());

        $this->assertEquals(20, $summary->getCell('G12')->getValue());
        $this->assertEquals(60, $summary->getCell('J12')->getValue());

        $this->assertStringContainsString('Subtotal', $summary->getCell('B13')->getValue());
        $this->assertEquals(30, $summary->getCell('F13')->getValue());
        $this->assertEquals(20, $summary->getCell('G13')->getValue());
        $this->assertEquals(60, $summary->getCell('J13')->getValue());

        $this->assertStringContainsString('Item Group: General', $summary->getCell('B14')->getValue());
        $this->assertEquals(60, $summary->getCell('J14')->getValue());

        $this->assertStringContainsString('Location: Samarinda', $summary->getCell('B15')->getValue());
        $this->assertEquals(60, $summary->getCell('J15')->getValue());

        $this->assertEquals('GRAND TOTAL', $summary->getCell('A16')->getValue());
        $this->assertEquals(30, $summary->getCell('F16')->getValue());
        $this->assertEquals(20, $summary->getCell('G16')->getValue());
        $this->assertEquals(60, $summary->getCell('J16')->getValue());

        // Detail sheet: header row + only the 2 in-range transactions (pre-period B/F source excluded).
        $this->assertEquals(3, $detail->getHighestRow());
        $this->assertEquals('ITM001', $detail->getCell('B2')->getValue());
        $this->assertEquals('Semen Portland 50kg', $detail->getCell('C2')->getValue());
    }

    public function test_summary_sheet_ignores_voucher_type_filter_but_detail_sheet_applies_it(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM003', 'item_name' => 'Cat Tembok', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 20000,
        ]);

        $service = app(StockLedgerService::class);
        $dateFrom = now()->subDays(3)->toDateString();
        $dateTo = now()->toDateString();

        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::GOODS_RECEIPT,
            voucherId: (string) Str::uuid(), qtyChange: 40, postingDatetime: now()->subDays(2),
        );
        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::OUT, voucherType: StockVoucherType::DELIVERY,
            voucherId: (string) Str::uuid(), qtyChange: -15, postingDatetime: now()->subDays(1),
        );

        // Filtering the export by voucher_type=delivery should still show BOTH rows and the true
        // closing balance (25) in Summary, but only the delivery row in Detail.
        $spreadsheet = $this->downloadWorkbook("date_from={$dateFrom}&date_to={$dateTo}&voucher_type=delivery");
        $summary = $spreadsheet->getSheetByName('Summary');
        $detail = $spreadsheet->getSheetByName('Detail');

        $this->assertEquals(0, $summary->getCell('J10')->getValue()); // B/F: no prior history
        $this->assertEquals(40, $summary->getCell('J11')->getValue()); // +40 goods receipt still present
        $this->assertEquals(25, $summary->getCell('J12')->getValue()); // -15 delivery -> closing 25
        $this->assertEquals(25, $summary->getCell('J13')->getValue()); // item subtotal closing qty

        $this->assertEquals(2, $detail->getHighestRow()); // header + only the delivery row
        $this->assertEquals('Delivery', $detail->getCell('F2')->getValue());
    }

    public function test_export_with_no_date_filters_resolves_filename_from_actual_transaction_dates(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM002', 'item_name' => 'Besi 10mm', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 15000,
        ]);

        $service = app(StockLedgerService::class);
        $earliest = now()->subDays(7);
        $latest = now()->subDays(2);
        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::OPENING_STOCK,
            voucherId: (string) Str::uuid(), qtyChange: 10, postingDatetime: $earliest,
        );
        $service->record(
            itemId: $item->id, warehouseId: $warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::GOODS_RECEIPT,
            voucherId: (string) Str::uuid(), qtyChange: 5, postingDatetime: $latest,
        );

        $response = $this->get('/api/v1/stock-ledger/export');
        $response->assertOk();

        $expectedName = sprintf('Stock_Ledger_%s_%s.xlsx', $earliest->format('dmY'), $latest->format('dmY'));
        $this->assertStringContainsString($expectedName, $response->headers->get('content-disposition'));
    }
}
