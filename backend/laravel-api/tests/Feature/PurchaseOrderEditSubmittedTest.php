<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Submitted Purchase Order used to be fully locked — this stakeholder-driven relaxation
 * (PurchaseOrderService::updateSubmitted()/syncSubmittedItems(), same shape as
 * SalesOrderService::updateApproved()/syncApprovedItems()) lets it still be corrected, but locks
 * any line that already has goods received against it.
 */
class PurchaseOrderEditSubmittedTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;

    protected GoodsReceiptService $goodsReceiptService;

    protected Supplier $supplier;

    protected Warehouse $warehouse;

    protected Item $item;

    protected Item $otherItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Ton']);
        $this->item = Item::query()->create(['item_code' => 'CEM-1', 'item_name' => 'Semen Curah', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000000]);
        $this->otherItem = Item::query()->create(['item_code' => 'CEM-2', 'item_name' => 'Semen Lain', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 500000]);
    }

    protected function submittedPurchaseOrder(array $items): \App\Models\PurchaseOrder
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => $items,
        ]);
        $this->approveDocument($purchaseOrder);

        return $this->purchaseOrderService->submit($purchaseOrder);
    }

    public function test_header_and_unreceived_line_are_freely_editable_after_submit(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder([
            ['item_id' => $this->item->id, 'qty' => 10, 'rate' => 1000000],
        ]);
        $line = $purchaseOrder->items->first();

        $updated = $this->purchaseOrderService->update($purchaseOrder, [
            'remarks' => 'Corrected after submit',
            'items' => [
                ['id' => $line->id, 'item_id' => $this->item->id, 'qty' => 20, 'rate' => 900000],
            ],
        ]);

        $this->assertSame('submitted', $updated->status->value, 'still submitted — edit never reverts status');
        $this->assertSame('Corrected after submit', $updated->remarks);
        $this->assertEquals(20, (float) $updated->items->first()->qty);
        $this->assertEquals(900000, (float) $updated->items->first()->rate);
        $this->assertEquals(18000000, (float) $updated->total_amount);
        $this->assertSame($line->id, $updated->items->first()->id, 'same row updated in place, not delete-and-recreate');
    }

    public function test_line_already_received_against_cannot_be_changed_or_removed(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder([
            ['item_id' => $this->item->id, 'qty' => 10, 'rate' => 1000000],
        ]);
        $line = $purchaseOrder->items->first();

        $receipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $line->id, 'qty' => 4]],
        ]);
        $this->goodsReceiptService->submit($receipt);

        $this->assertEquals(4, (float) $line->fresh()->received_qty);

        try {
            $this->purchaseOrderService->update($purchaseOrder->fresh(), [
                'items' => [
                    ['id' => $line->id, 'item_id' => $this->item->id, 'qty' => 5, 'rate' => 1000000],
                ],
            ]);
            $this->fail('Expected changing a received line to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('already has goods received against it', $e->getMessage());
        }

        try {
            $this->purchaseOrderService->update($purchaseOrder->fresh(), [
                'items' => [
                    ['item_id' => $this->otherItem->id, 'qty' => 3, 'rate' => 500000],
                ],
            ]);
            $this->fail('Expected dropping a received line to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('already has goods received against it', $e->getMessage());
        }

        // Resubmitting the exact same line unchanged, alongside a brand-new line, succeeds.
        $updated = $this->purchaseOrderService->update($purchaseOrder->fresh(), [
            'items' => [
                ['id' => $line->id, 'item_id' => $this->item->id, 'qty' => 10, 'rate' => 1000000],
                ['item_id' => $this->otherItem->id, 'qty' => 3, 'rate' => 500000],
            ],
        ]);

        $this->assertCount(2, $updated->items);
        $this->assertEquals(4, (float) $updated->items->firstWhere('id', $line->id)->received_qty, 'received_qty preserved on the untouched locked line');
    }

    public function test_new_line_can_be_added_to_a_submitted_order(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder([
            ['item_id' => $this->item->id, 'qty' => 10, 'rate' => 1000000],
        ]);
        $line = $purchaseOrder->items->first();

        $updated = $this->purchaseOrderService->update($purchaseOrder, [
            'items' => [
                ['id' => $line->id, 'item_id' => $this->item->id, 'qty' => 10, 'rate' => 1000000],
                ['item_id' => $this->otherItem->id, 'qty' => 2, 'rate' => 500000],
            ],
        ]);

        $this->assertCount(2, $updated->items);
        $this->assertEquals(11000000, (float) $updated->total_amount);
    }

    public function test_cancelled_purchase_order_still_cannot_be_updated(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder([
            ['item_id' => $this->item->id, 'qty' => 10, 'rate' => 1000000],
        ]);
        $purchaseOrder = $this->purchaseOrderService->cancel($purchaseOrder);

        try {
            $this->purchaseOrderService->update($purchaseOrder, ['remarks' => 'Should not apply']);
            $this->fail('Expected updating a cancelled PO to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('Only draft Purchase Orders can be updated', $e->getMessage());
        }
    }
}
