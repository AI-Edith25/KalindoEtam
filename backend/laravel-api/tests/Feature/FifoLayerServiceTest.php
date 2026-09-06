<?php

namespace Tests\Feature;

use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\FifoLayer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\FifoLayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FIFO cost layer engine — the minimum coverage the ticket asks for: single-layer
 * consumption, cross-layer consumption, transfer cost-carry, cancellation/reversal,
 * decimal qty, and insufficient-stock rejection.
 */
class FifoLayerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FifoLayerService $fifo;

    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fifo = app(FifoLayerService::class);

        $this->warehouseA = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $this->warehouseB = Warehouse::query()->create(['name' => 'Balikpapan', 'code' => 'BPP', 'warehouse_type' => WarehouseType::TRANSIT]);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);
    }

    private function receive(Warehouse $warehouse, float $qty, float $unitCost, ?string $sourceId = null): FifoLayer
    {
        return $this->fifo->receive(
            itemId: $this->item->id,
            warehouseId: $warehouse->id,
            qty: $qty,
            unitCost: $unitCost,
            sourceType: StockVoucherType::GOODS_RECEIPT,
            sourceId: $sourceId ?? (string) Str::uuid(),
            sourceDocumentNumber: 'GR-TEST',
            receivedDate: now(),
        );
    }

    public function test_single_layer_consumption(): void
    {
        $this->receive($this->warehouseA, 100, 60000);

        $result = $this->fifo->consume($this->item->id, $this->warehouseA->id, 40, StockVoucherType::DELIVERY, (string) Str::uuid());

        $this->assertSame(40.0 * 60000, $result->totalCost);
        $this->assertSame(60000.0, $result->weightedAverageUnitCost);
        $this->assertCount(1, $result->lines);
        $this->assertEquals(60.0, (float) FifoLayer::query()->sole()->qty_remaining);
    }

    public function test_cross_layer_consumption_splits_oldest_first(): void
    {
        $this->receive($this->warehouseA, 30, 60000)->update(['received_date' => now()->subDays(2)]);
        $this->receive($this->warehouseA, 30, 65000)->update(['received_date' => now()->subDay()]);

        $result = $this->fifo->consume($this->item->id, $this->warehouseA->id, 40, StockVoucherType::DELIVERY, (string) Str::uuid());

        // 30 units @ 60000 (fully drained, oldest layer) + 10 units @ 65000 (from the newer layer).
        $this->assertSame(30 * 60000.0 + 10 * 65000.0, $result->totalCost);
        $this->assertCount(2, $result->lines);

        $layers = FifoLayer::query()->orderBy('received_date')->get();
        $this->assertEquals(0.0, (float) $layers[0]->qty_remaining);
        $this->assertEquals(20.0, (float) $layers[1]->qty_remaining);
    }

    public function test_transfer_carries_consumed_cost_to_the_destination_layer(): void
    {
        $this->receive($this->warehouseA, 20, 60000)->update(['received_date' => now()->subDays(2)]);
        $this->receive($this->warehouseA, 20, 70000)->update(['received_date' => now()->subDay()]);

        $transferId = (string) Str::uuid();
        $consumption = $this->fifo->consume($this->item->id, $this->warehouseA->id, 30, StockVoucherType::STOCK_TRANSFER, $transferId);

        // 20 @ 60000 + 10 @ 70000 = 1,900,000 / 30 = 63,333.33
        $this->assertEqualsWithDelta(63333.33, $consumption->weightedAverageUnitCost, 0.01);

        $destinationLayer = $this->fifo->receive(
            itemId: $this->item->id,
            warehouseId: $this->warehouseB->id,
            qty: 30,
            unitCost: $consumption->weightedAverageUnitCost,
            sourceType: StockVoucherType::STOCK_TRANSFER,
            sourceId: $transferId,
            sourceDocumentNumber: 'ST-TEST',
            receivedDate: now(),
        );

        $this->assertEquals(30.0, (float) $destinationLayer->qty_remaining);
        $this->assertEqualsWithDelta(63333.33, (float) $destinationLayer->unit_cost, 0.01);
    }

    public function test_insufficient_layers_rejects_the_transaction(): void
    {
        $this->receive($this->warehouseA, 10, 60000);

        $this->expectException(BusinessException::class);
        $this->fifo->consume($this->item->id, $this->warehouseA->id, 15, StockVoucherType::DELIVERY, (string) Str::uuid());

        // Never partially applied — the layer is untouched.
        $this->assertEquals(10.0, (float) FifoLayer::query()->sole()->qty_remaining);
    }

    public function test_decimal_qty_consumption(): void
    {
        $this->receive($this->warehouseA, 100.5, 60000);

        $result = $this->fifo->consume($this->item->id, $this->warehouseA->id, 52.9, StockVoucherType::DELIVERY, (string) Str::uuid());

        $this->assertEqualsWithDelta(52.9 * 60000, $result->totalCost, 0.01);
        $this->assertEquals(47.6, round((float) FifoLayer::query()->sole()->qty_remaining, 4));
    }

    public function test_reverse_receipt_undoes_an_unconsumed_layer(): void
    {
        $sourceId = (string) Str::uuid();
        $this->receive($this->warehouseA, 50, 60000, $sourceId);

        $this->fifo->reverseReceipt(StockVoucherType::GOODS_RECEIPT, $sourceId);

        $this->assertSame(0, FifoLayer::query()->count());
    }

    public function test_reverse_receipt_is_rejected_once_partially_consumed(): void
    {
        $sourceId = (string) Str::uuid();
        $this->receive($this->warehouseA, 50, 60000, $sourceId);
        $this->fifo->consume($this->item->id, $this->warehouseA->id, 10, StockVoucherType::DELIVERY, (string) Str::uuid());

        $this->expectException(BusinessException::class);
        $this->fifo->reverseReceipt(StockVoucherType::GOODS_RECEIPT, $sourceId);
    }

    public function test_reverse_consumption_restores_the_exact_original_layers(): void
    {
        $this->receive($this->warehouseA, 30, 60000)->update(['received_date' => now()->subDays(2)]);
        $this->receive($this->warehouseA, 30, 70000)->update(['received_date' => now()->subDay()]);

        $returnId = (string) Str::uuid();
        $this->fifo->consume($this->item->id, $this->warehouseA->id, 40, StockVoucherType::PURCHASE_RETURN, $returnId);

        $layersAfterConsume = FifoLayer::query()->orderBy('received_date')->get();
        $this->assertEquals(0.0, (float) $layersAfterConsume[0]->qty_remaining);
        $this->assertEquals(20.0, (float) $layersAfterConsume[1]->qty_remaining);

        $this->fifo->reverseConsumption(StockVoucherType::PURCHASE_RETURN, $returnId);

        $layersAfterReverse = FifoLayer::query()->orderBy('received_date')->get();
        $this->assertEquals(30.0, (float) $layersAfterReverse[0]->qty_remaining);
        $this->assertEquals(30.0, (float) $layersAfterReverse[1]->qty_remaining);
    }

    public function test_reverse_consumption_is_idempotent(): void
    {
        $this->receive($this->warehouseA, 30, 60000);
        $returnId = (string) Str::uuid();
        $this->fifo->consume($this->item->id, $this->warehouseA->id, 10, StockVoucherType::PURCHASE_RETURN, $returnId);

        $this->fifo->reverseConsumption(StockVoucherType::PURCHASE_RETURN, $returnId);
        $this->fifo->reverseConsumption(StockVoucherType::PURCHASE_RETURN, $returnId);

        // Calling it twice must not double-restore qty.
        $this->assertEquals(30.0, (float) FifoLayer::query()->sole()->qty_remaining);
    }

    public function test_average_consumed_cost_for_a_voucher_and_item(): void
    {
        $this->receive($this->warehouseA, 20, 60000)->update(['received_date' => now()->subDays(2)]);
        $this->receive($this->warehouseA, 20, 70000)->update(['received_date' => now()->subDay()]);

        $deliveryId = (string) Str::uuid();
        $this->fifo->consume($this->item->id, $this->warehouseA->id, 30, StockVoucherType::DELIVERY, $deliveryId);

        $average = $this->fifo->averageConsumedCost($this->item->id, StockVoucherType::DELIVERY, $deliveryId);

        $this->assertEqualsWithDelta(63333.33, $average, 0.01);
    }
}
