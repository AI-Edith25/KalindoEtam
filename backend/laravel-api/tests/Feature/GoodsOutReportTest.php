<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Repositories\DeliveryRepository;
use App\Services\DeliveryService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Batch B — Goods Out Report extends the existing Delivery list with customer_id/item_id filters; no new endpoint. */
class GoodsOutReportTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected DeliveryRepository $deliveryRepository;
    protected Customer $customerA;
    protected Customer $customerB;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->deliveryRepository = app(DeliveryRepository::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customerA = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->customerB = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Beta']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);

        app(StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 1000,
            postingDatetime: now(),
        );
    }

    protected function deliveryFor(Customer $customer, int $qty = 5): \App\Models\Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => 10000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        return $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
    }

    public function test_customer_id_filter_narrows_to_that_customers_deliveries(): void
    {
        $this->deliveryFor($this->customerA);
        $this->deliveryFor($this->customerB);

        $filtered = $this->deliveryRepository->search(['customer_id' => $this->customerA->id]);

        $this->assertCount(1, $filtered->items());
        $this->assertEquals($this->customerA->id, $filtered->items()[0]->customer_id);
    }

    public function test_item_id_filter_narrows_to_deliveries_containing_that_item(): void
    {
        $this->deliveryFor($this->customerA);

        $filtered = $this->deliveryRepository->search(['item_id' => $this->item->id]);
        $this->assertCount(1, $filtered->items());

        $otherItemGroup = \App\Models\ItemGroup::query()->create(['name' => 'Other']);
        $otherUom = \App\Models\UnitOfMeasurement::query()->create(['name' => 'Box']);
        $otherItem = Item::query()->create([
            'item_code' => 'ITM-2', 'item_name' => 'Gadget', 'item_group_id' => $otherItemGroup->id, 'uom_id' => $otherUom->id, 'standard_rate' => 5000,
        ]);

        $emptyResult = $this->deliveryRepository->search(['item_id' => $otherItem->id]);
        $this->assertCount(0, $emptyResult->items());
    }

    public function test_warehouse_and_date_filters_still_work_alongside_new_filters(): void
    {
        $this->deliveryFor($this->customerA);

        $results = $this->deliveryRepository->search([
            'customer_id' => $this->customerA->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertCount(1, $results->items());
    }
}
