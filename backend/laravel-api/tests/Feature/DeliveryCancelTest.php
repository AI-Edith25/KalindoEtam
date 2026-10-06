<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\SalesOrderItem;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeliveryCancelTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected DeliveryService $deliveryService;

    protected StockLedgerService $stockLedgerService;

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
        $this->stockLedgerService = app(StockLedgerService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create(['item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000]);

        $this->seedStock($this->item->id, $this->warehouse->id, 1000);
    }

    /** A Pending Delivery of 10 against an approved 20-qty Sales Order. */
    protected function pendingDelivery(): Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 20, 'rate' => 10000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        return $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 10]],
        ]);
    }

    public function test_pending_delivery_can_be_cancelled_without_moving_stock(): void
    {
        $delivery = $this->pendingDelivery();
        $balanceBefore = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);

        $cancelled = $this->deliveryService->cancel($delivery);

        $this->assertSame(DeliveryStatus::CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertEquals($balanceBefore, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id), 'nothing was posted, so stock is unchanged');
        $this->assertEquals(0, SalesOrderItem::query()->first()->delivered_qty, 'a pending Delivery never advances delivered_qty');
        $this->assertDatabaseHas((new AuditLog)->getTable(), ['action' => 'cancelled', 'module' => 'delivery']);
    }

    public function test_complete_delivery_cannot_be_cancelled(): void
    {
        $delivery = $this->pendingDelivery();
        $this->deliveryService->complete($delivery);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Only pending Deliveries can be cancelled.');

        $this->deliveryService->cancel($delivery->fresh());
    }

    /** Submit's own status guard rejects it (422), and the transaction rolls back any stock posted before that. */
    public function test_cancelled_delivery_cannot_be_completed(): void
    {
        $delivery = $this->deliveryService->cancel($this->pendingDelivery());
        $balanceBefore = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);

        $this->expectException(HttpException::class);

        try {
            $this->deliveryService->complete($delivery);
        } finally {
            $this->assertEquals($balanceBefore, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id), 'no stock moved');
        }
    }

    public function test_cancelled_delivery_cannot_be_edited(): void
    {
        $delivery = $this->deliveryService->cancel($this->pendingDelivery());

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Only pending Deliveries can be updated.');

        $this->deliveryService->update($delivery, ['remarks' => 'should not save']);
    }

    public function test_cancelled_delivery_cannot_be_deleted(): void
    {
        $delivery = $this->deliveryService->cancel($this->pendingDelivery());

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Only pending Deliveries can be deleted.');

        $this->deliveryService->delete($delivery);
    }
}
