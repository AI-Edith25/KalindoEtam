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
 * A Delivery can combine two or more approved Sales Orders into a single
 * document — mirrors InvoiceWorkflowTest's multi-Delivery merge coverage
 * (delivery_sales_orders pivot, same-Customer/same-Warehouse guards,
 * deterministic anchor), one level up the chain.
 */
class DeliveryMultiSalesOrderTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected DeliveryService $deliveryService;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Item $item;

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
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        $this->seedStock($this->item->id, $this->warehouse->id, 1000);
    }

    protected function approvedSalesOrder(?Customer $customer = null, ?string $warehouseId = null, int $qty = 10, float $rate = 10000): \App\Models\SalesOrder
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'warehouse_id' => $warehouseId,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        return $salesOrder;
    }

    public function test_delivery_can_be_created_from_multiple_sales_orders_of_the_same_customer(): void
    {
        $soA = $this->approvedSalesOrder(qty: 10, rate: 10000);
        $soB = $this->approvedSalesOrder(qty: 5, rate: 20000);

        $delivery = $this->deliveryService->create([
            'sales_order_ids' => [$soA->id, $soB->id],
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['sales_order_item_id' => $soA->items->first()->id, 'qty' => 10],
                ['sales_order_item_id' => $soB->items->first()->id, 'qty' => 5],
            ],
        ]);

        $this->assertCount(2, $delivery->items);
        $this->assertCount(2, $delivery->salesOrders);
        $this->assertEqualsCanonicalizing([$soA->id, $soB->id], $delivery->salesOrders->pluck('id')->all());
        $this->assertContains($delivery->sales_order_id, [$soA->id, $soB->id]);
    }

    public function test_merging_sales_orders_from_different_customers_is_rejected(): void
    {
        $soA = $this->approvedSalesOrder();
        $otherCustomer = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Wayne Inc']);
        $soB = $this->approvedSalesOrder($otherCustomer);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('same Customer');

        $this->deliveryService->create([
            'sales_order_ids' => [$soA->id, $soB->id],
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['sales_order_item_id' => $soA->items->first()->id, 'qty' => 10],
                ['sales_order_item_id' => $soB->items->first()->id, 'qty' => 10],
            ],
        ]);
    }

    public function test_merging_sales_orders_from_different_warehouses_is_rejected(): void
    {
        $otherWarehouse = Warehouse::query()->create(['name' => 'Other WH', 'code' => 'WH2', 'warehouse_type' => WarehouseType::MAIN]);
        $this->seedStock($this->item->id, $otherWarehouse->id, 1000);
        $soA = $this->approvedSalesOrder(warehouseId: $this->warehouse->id);
        $soB = $this->approvedSalesOrder(warehouseId: $otherWarehouse->id);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('same Warehouse');

        $this->deliveryService->create([
            'sales_order_ids' => [$soA->id, $soB->id],
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['sales_order_item_id' => $soA->items->first()->id, 'qty' => 10],
                ['sales_order_item_id' => $soB->items->first()->id, 'qty' => 10],
            ],
        ]);
    }

    public function test_a_sales_order_not_yet_approved_cannot_be_merged_into_a_delivery(): void
    {
        $soA = $this->approvedSalesOrder();
        $soB = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000]],
        ]);

        $this->expectException(BusinessException::class);

        $this->deliveryService->create([
            'sales_order_ids' => [$soA->id, $soB->id],
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['sales_order_item_id' => $soA->items->first()->id, 'qty' => 10],
                ['sales_order_item_id' => $soB->items->first()->id, 'qty' => 5],
            ],
        ]);
    }

    public function test_completing_a_delivery_fails_if_one_of_its_source_sales_orders_was_cancelled_after_creation(): void
    {
        $soA = $this->approvedSalesOrder(qty: 10, rate: 10000);
        $soB = $this->approvedSalesOrder(qty: 5, rate: 20000);

        $delivery = $this->deliveryService->create([
            'sales_order_ids' => [$soA->id, $soB->id],
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['sales_order_item_id' => $soA->items->first()->id, 'qty' => 10],
                ['sales_order_item_id' => $soB->items->first()->id, 'qty' => 5],
            ],
        ]);

        $this->salesOrderService->cancel($soB);

        $this->expectException(BusinessException::class);

        $this->deliveryService->complete($delivery);
    }

    /** The older singular sales_order_id (pre-dating this feature) must keep working unchanged — every existing direct-service caller relies on it. */
    public function test_create_still_accepts_the_singular_sales_order_id_field(): void
    {
        $salesOrder = $this->approvedSalesOrder(qty: 10, rate: 10000);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 10]],
        ]);

        $this->assertSame($salesOrder->id, $delivery->sales_order_id);
        $this->assertCount(1, $delivery->salesOrders);
    }
}
