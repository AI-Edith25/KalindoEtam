<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An Approved Sales Order used to be fully locked (SalesOrderService::assertDraft()). Now it
 * can be corrected, except for a line a Delivery already references — restrictOnDelete() on
 * delivery_items.sales_order_item_id makes deleting that line impossible at the DB level, and
 * that lock kicks in as soon as the Delivery is created, before it's ever completed. See
 * SalesOrderService::updateApproved()/syncApprovedItems().
 */
class SalesOrderEditApprovedTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $itemA;
    protected Item $itemB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme', 'credit_limit' => null]);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->itemA = Item::query()->create([
            'item_code' => 'ITM-A', 'item_name' => 'Widget A', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
        $this->itemB = Item::query()->create([
            'item_code' => 'ITM-B', 'item_name' => 'Widget B', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 15000,
        ]);

        $this->seedStock($this->itemA->id, $this->warehouse->id, 1000);
        $this->seedStock($this->itemB->id, $this->warehouse->id, 1000);
    }

    protected function approvedOrder(array $items): \App\Models\SalesOrder
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => $items,
        ]);
        $this->approveDocument($salesOrder);

        return $this->salesOrderService->approve($salesOrder);
    }

    public function test_approved_order_with_no_deliveries_allows_full_item_replace(): void
    {
        $order = $this->approvedOrder([
            ['item_id' => $this->itemA->id, 'qty' => 5, 'rate' => 10000],
        ]);

        $updated = $this->salesOrderService->update($order, [
            'remarks' => 'Edited after approval.',
            'items' => [
                ['item_id' => $this->itemB->id, 'qty' => 3, 'rate' => 15000],
            ],
        ]);

        $this->assertEquals('Edited after approval.', $updated->remarks);
        $this->assertCount(1, $updated->items);
        $this->assertEquals($this->itemB->id, $updated->items->first()->item_id);
        $this->assertEquals(3 * 15000, (float) $updated->total_amount);
    }

    public function test_line_with_pending_delivery_locks_even_before_completion(): void
    {
        $order = $this->approvedOrder([
            ['item_id' => $this->itemA->id, 'qty' => 5, 'rate' => 10000],
            ['item_id' => $this->itemB->id, 'qty' => 2, 'rate' => 15000],
        ]);
        $lockedLine = $order->items->firstWhere('item_id', $this->itemA->id);
        $freeLine = $order->items->firstWhere('item_id', $this->itemB->id);

        // Still Pending — complete() never called — but the DeliveryItem already exists.
        $this->deliveryService->create([
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $lockedLine->id, 'qty' => 2]],
        ]);

        // Free line qty change + a new line both succeed; the locked line is carried through unchanged.
        $updated = $this->salesOrderService->update($order, [
            'items' => [
                ['id' => $lockedLine->id, 'item_id' => $this->itemA->id, 'qty' => 5, 'rate' => 10000],
                ['id' => $freeLine->id, 'item_id' => $this->itemB->id, 'qty' => 4, 'rate' => 15000],
                ['item_id' => $this->itemA->id, 'qty' => 1, 'rate' => 10000],
            ],
        ]);

        $this->assertCount(3, $updated->items);
        $this->assertEquals(4, $updated->items->firstWhere('id', $freeLine->id)->qty);
    }

    public function test_changing_qty_on_a_line_with_delivery_is_rejected(): void
    {
        $order = $this->approvedOrder([
            ['item_id' => $this->itemA->id, 'qty' => 5, 'rate' => 10000],
        ]);
        $line = $order->items->first();

        $this->deliveryService->create([
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $line->id, 'qty' => 2]],
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already has a Delivery');

        $this->salesOrderService->update($order, [
            'items' => [
                ['id' => $line->id, 'item_id' => $this->itemA->id, 'qty' => 9, 'rate' => 10000],
            ],
        ]);
    }

    public function test_removing_a_line_with_delivery_is_rejected(): void
    {
        $order = $this->approvedOrder([
            ['item_id' => $this->itemA->id, 'qty' => 5, 'rate' => 10000],
            ['item_id' => $this->itemB->id, 'qty' => 2, 'rate' => 15000],
        ]);
        $lockedLine = $order->items->firstWhere('item_id', $this->itemA->id);
        $freeLine = $order->items->firstWhere('item_id', $this->itemB->id);

        $this->deliveryService->create([
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $lockedLine->id, 'qty' => 2]],
        ]);

        $this->expectException(BusinessException::class);

        // Locked line silently dropped from the payload — must be rejected, not honored.
        $this->salesOrderService->update($order, [
            'items' => [
                ['id' => $freeLine->id, 'item_id' => $this->itemB->id, 'qty' => 2, 'rate' => 15000],
            ],
        ]);
    }

    public function test_credit_check_refires_on_approved_order_edit(): void
    {
        $this->customer->update(['credit_limit' => 1_000_000]);
        $order = $this->approvedOrder([
            ['item_id' => $this->itemA->id, 'qty' => 1, 'rate' => 100000],
        ]);

        // Limit tightens after approval — same "drift" scenario SalesOrderCreditCheckTest
        // already covers for approve(), now also reachable via a post-approval edit.
        $this->customer->update(['credit_limit' => 50000]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Limit kredit');

        $this->salesOrderService->update($order, ['remarks' => 'Trying to sneak a header-only edit past credit.']);
    }

    public function test_stock_check_refires_on_approved_order_item_edit(): void
    {
        $order = $this->approvedOrder([
            ['item_id' => $this->itemA->id, 'qty' => 5, 'rate' => 10000],
        ]);

        $this->expectException(BusinessException::class);

        $this->salesOrderService->update($order, [
            'items' => [
                ['item_id' => $this->itemA->id, 'qty' => 999999, 'rate' => 10000],
            ],
        ]);
    }
}
