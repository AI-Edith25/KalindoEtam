<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\FifoLayer;
use App\Models\FifoLayerConsumption;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\SalesOrder;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simulates a pre-FIFO database: real GoodsReceipt/Delivery rows and their matching
 * StockLedger entries exist (as they would have before this feature shipped), but no
 * FifoLayer/FifoLayerConsumption at all — created directly via Eloquent, bypassing
 * GoodsReceiptService/DeliveryService, since those now create layers immediately and would
 * defeat the point of testing the backfill command itself.
 */
class BackfillFifoLayersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected Item $item;

    protected Item $itemWithNoRate;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);
        $this->itemWithNoRate = Item::query()->create([
            'item_code' => 'ITM999', 'item_name' => 'Item Tanpa Rate', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 0,
        ]);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT Supplier']);
    }

    private function historicalGoodsReceipt(Item $item, float $qty, float $rate): GoodsReceipt
    {
        $gr = GoodsReceipt::query()->create([
            'status' => DocumentStatus::SUBMITTED,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->subDays(10),
            'due_date' => now()->addDays(20),
        ]);

        GoodsReceiptItem::query()->create([
            'goods_receipt_id' => $gr->id,
            'item_id' => $item->id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'uom' => 'Zak',
            'qty' => $qty,
            'rate' => $rate,
            'amount' => $qty * $rate,
        ]);

        app(StockLedgerService::class)->record(
            itemId: $item->id, warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN, voucherType: StockVoucherType::GOODS_RECEIPT,
            voucherId: $gr->id, qtyChange: $qty, postingDatetime: $gr->receipt_date,
        );

        return $gr;
    }

    public function test_backfills_goods_receipt_and_delivery_and_reconciles_cleanly(): void
    {
        $gr = $this->historicalGoodsReceipt($this->item, 30, 58000);

        $customer = Customer::query()->create(['customer_code' => 'CUS1', 'customer_name' => 'PT Customer']);
        $salesOrder = SalesOrder::query()->create([
            'status' => \App\Enums\SalesOrderStatus::APPROVED,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->subDays(6),
        ]);
        $salesOrderItem = \App\Models\SalesOrderItem::query()->create([
            'sales_order_id' => $salesOrder->id,
            'item_id' => $this->item->id,
            'item_code' => $this->item->item_code,
            'item_name' => $this->item->item_name,
            'uom' => 'Zak',
            'rate' => 65000,
            'qty' => 10,
            'amount' => 650000,
        ]);

        $delivery = Delivery::query()->create([
            'status' => DeliveryStatus::COMPLETE,
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->subDays(5),
            'due_date' => now()->addDays(25),
        ]);
        DeliveryItem::query()->create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $salesOrderItem->id,
            'item_id' => $this->item->id,
            'item_code' => $this->item->item_code,
            'item_name' => $this->item->item_name,
            'uom' => 'Zak',
            'rate' => 65000,
            'qty' => 10,
            'amount' => 650000,
        ]);
        app(StockLedgerService::class)->record(
            itemId: $this->item->id, warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::OUT, voucherType: StockVoucherType::DELIVERY,
            voucherId: $delivery->id, qtyChange: -10, postingDatetime: $delivery->delivery_date,
        );

        $this->artisan('fifo:backfill')->assertExitCode(0);

        $layer = FifoLayer::query()->where('source_id', $gr->id)->sole();
        $this->assertEquals(58000, (float) $layer->unit_cost);
        $this->assertEquals(20, (float) $layer->qty_remaining); // 30 received - 10 delivered

        $consumption = FifoLayerConsumption::query()
            ->where('consuming_source_type', StockVoucherType::DELIVERY->value)
            ->where('consuming_source_id', $delivery->id)
            ->sole();
        $this->assertEquals(10, (float) $consumption->qty_consumed);
        $this->assertEquals(58000, (float) $consumption->unit_cost);
    }

    public function test_running_twice_does_not_create_duplicate_layers(): void
    {
        $this->historicalGoodsReceipt($this->item, 30, 58000);

        $this->artisan('fifo:backfill')->assertExitCode(0);
        $firstCount = FifoLayer::query()->count();

        $this->artisan('fifo:backfill')->assertExitCode(0);
        $secondCount = FifoLayer::query()->count();

        $this->assertSame(1, $firstCount);
        $this->assertSame($firstCount, $secondCount);
    }

    public function test_dry_run_reports_without_saving(): void
    {
        $this->historicalGoodsReceipt($this->item, 30, 58000);

        $this->artisan('fifo:backfill --dry-run')->assertExitCode(0);

        $this->assertSame(0, FifoLayer::query()->count());
    }

    public function test_a_zero_rate_line_is_skipped_and_reported_not_defaulted(): void
    {
        $this->historicalGoodsReceipt($this->itemWithNoRate, 15, 0);

        $this->artisan('fifo:backfill')
            ->expectsOutputToContain('ITM999')
            ->assertExitCode(0);

        $this->assertSame(0, FifoLayer::query()->where('item_id', $this->itemWithNoRate->id)->count());
    }

    public function test_reconciliation_flags_a_mismatch_when_a_line_could_not_be_backfilled(): void
    {
        $this->historicalGoodsReceipt($this->itemWithNoRate, 15, 0);

        // Ledger says 15 on hand, but no layer could be created (missing rate) -> a real mismatch.
        $this->artisan('fifo:backfill')
            ->expectsOutputToContain('ITM999')
            ->assertExitCode(0);

        $this->assertSame(15.0, (float) \App\Models\StockLedger::query()->where('item_id', $this->itemWithNoRate->id)->value('balance_qty'));
        $this->assertSame(0, FifoLayer::query()->where('item_id', $this->itemWithNoRate->id)->count());
    }
}
