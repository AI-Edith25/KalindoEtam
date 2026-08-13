<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use App\Services\StockTransferService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stock Transfer: direct-effect warehouse-to-warehouse move. No journal
 * entry — this Chart of Accounts has a single global Inventory account
 * (1300), not one per warehouse, confirmed with the user before building
 * this (a debit/credit pair against the same account would be a
 * self-canceling no-op).
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    protected StockTransferService $stockTransferService;
    protected StockLedgerService $stockLedgerService;
    protected Warehouse $source;
    protected Warehouse $destination;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->stockTransferService = app(StockTransferService::class);
        $this->stockLedgerService = app(StockLedgerService::class);

        $this->source = Warehouse::query()->create(['name' => 'Cabang', 'code' => 'WH-SRC', 'warehouse_type' => WarehouseType::MAIN]);
        $this->destination = Warehouse::query()->create(['name' => 'Pusat', 'code' => 'WH-DST', 'warehouse_type' => WarehouseType::MAIN]);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        $this->stockLedgerService->record(
            itemId: $this->item->id,
            warehouseId: $this->source->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 50,
            postingDatetime: now(),
        );
    }

    public function test_submit_moves_stock_out_of_source_and_into_destination(): void
    {
        $transfer = $this->stockTransferService->create([
            'source_warehouse_id' => $this->source->id,
            'destination_warehouse_id' => $this->destination->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 20]],
        ]);

        $this->stockTransferService->submit($transfer);

        $this->assertSame(30, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->source->id));
        $this->assertSame(20, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->destination->id));
        // current_stock is a company-wide total across all warehouses — a transfer redistributes it, doesn't change it.
        $this->assertSame(50, $this->item->fresh()->current_stock);
    }

    public function test_submit_rejects_qty_exceeding_available_stock_at_source(): void
    {
        $transfer = $this->stockTransferService->create([
            'source_warehouse_id' => $this->source->id,
            'destination_warehouse_id' => $this->destination->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 999]],
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Insufficient stock');

        $this->stockTransferService->submit($transfer);
    }

    public function test_create_rejects_identical_source_and_destination_warehouse(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('must be different');

        $this->stockTransferService->create([
            'source_warehouse_id' => $this->source->id,
            'destination_warehouse_id' => $this->source->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5]],
        ]);
    }

    public function test_submit_does_not_post_any_journal_entry(): void
    {
        $transfer = $this->stockTransferService->create([
            'source_warehouse_id' => $this->source->id,
            'destination_warehouse_id' => $this->destination->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10]],
        ]);

        $before = JournalEntry::query()->count();

        $this->stockTransferService->submit($transfer);

        $this->assertSame($before, JournalEntry::query()->count());
    }
}
