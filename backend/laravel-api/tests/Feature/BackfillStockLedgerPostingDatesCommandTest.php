<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\OpeningStock;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Simulates the pre-fix database: StockLedger rows written with posting_datetime = the real
 * submit time (now()), not the source document's own date, exactly as GoodsReceiptService/
 * OpeningStockService used to write them before the ledger-date fix shipped.
 */
class BackfillStockLedgerPostingDatesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
    }

    private function createItem(): Item
    {
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);

        return Item::query()->create([
            'item_code' => 'ITM-'.Str::random(6), 'item_name' => 'Test Item', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 0,
        ]);
    }

    public function test_corrects_a_row_whose_posting_datetime_disagrees_with_its_source_document(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme']);

        $goodsReceipt = GoodsReceipt::query()->create([
            'status' => DocumentStatus::SUBMITTED,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-22',
            'due_date' => '2026-10-22',
        ]);

        $item = $this->createItem();
        $ledger = StockLedger::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'transaction_type' => StockTransactionType::IN,
            'voucher_type' => StockVoucherType::GOODS_RECEIPT,
            'voucher_id' => $goodsReceipt->id,
            'qty_change' => 100,
            'balance_qty' => 100,
            'posting_datetime' => Carbon::parse('2026-09-25 14:23:00'), // wrong: real submit time, not receipt_date
        ]);

        $this->artisan('stock-ledger:backfill-posting-dates')->assertExitCode(0);

        $this->assertSame('2026-09-22', $ledger->fresh()->posting_datetime->format('Y-m-d'));
    }

    public function test_dry_run_reports_but_does_not_save(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);

        $openingStock = OpeningStock::query()->create([
            'status' => DocumentStatus::SUBMITTED,
            'warehouse_id' => $warehouse->id,
            'cutoff_date' => '2026-01-01',
        ]);

        $item = $this->createItem();
        $ledger = StockLedger::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'transaction_type' => StockTransactionType::IN,
            'voucher_type' => StockVoucherType::OPENING_STOCK,
            'voucher_id' => $openingStock->id,
            'qty_change' => 50,
            'balance_qty' => 50,
            'posting_datetime' => Carbon::parse('2026-01-05 09:00:00'),
        ]);

        $this->artisan('stock-ledger:backfill-posting-dates', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('2026-01-05', $ledger->fresh()->posting_datetime->format('Y-m-d'));
    }

    public function test_row_already_matching_its_source_document_is_left_untouched(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme']);

        $goodsReceipt = GoodsReceipt::query()->create([
            'status' => DocumentStatus::SUBMITTED,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-22',
            'due_date' => '2026-10-22',
        ]);

        $item = $this->createItem();
        $ledger = StockLedger::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'transaction_type' => StockTransactionType::IN,
            'voucher_type' => StockVoucherType::GOODS_RECEIPT,
            'voucher_id' => $goodsReceipt->id,
            'qty_change' => 100,
            'balance_qty' => 100,
            'posting_datetime' => Carbon::parse('2026-09-22 00:00:00'), // already correct
        ]);
        $originalUpdatedAt = $ledger->updated_at;

        $this->artisan('stock-ledger:backfill-posting-dates')->assertExitCode(0);

        $this->assertEquals($originalUpdatedAt, $ledger->fresh()->updated_at);
    }

    public function test_row_whose_source_document_no_longer_exists_is_skipped(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $item = $this->createItem();

        $ledger = StockLedger::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'transaction_type' => StockTransactionType::IN,
            'voucher_type' => StockVoucherType::GOODS_RECEIPT,
            'voucher_id' => (string) Str::uuid(), // no matching goods_receipts row
            'qty_change' => 100,
            'balance_qty' => 100,
            'posting_datetime' => Carbon::parse('2026-09-25 14:23:00'),
        ]);

        $this->artisan('stock-ledger:backfill-posting-dates')->assertExitCode(0);

        $this->assertSame('2026-09-25', $ledger->fresh()->posting_datetime->format('Y-m-d'));
    }
}
