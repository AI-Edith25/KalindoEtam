<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Repositories\SalesOrderRepository;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Batch B — Sales Report extends the existing Sales Order list with an item_id filter; no new endpoint. */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected SalesOrderRepository $salesOrderRepository;
    protected Customer $customer;
    protected Item $itemA;
    protected Item $itemB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->salesOrderRepository = app(SalesOrderRepository::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->itemA = Item::query()->create([
            'item_code' => 'ITM-A', 'item_name' => 'Widget A', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
        $this->itemB = Item::query()->create([
            'item_code' => 'ITM-B', 'item_name' => 'Widget B', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 15000,
        ]);
    }

    public function test_item_id_filter_narrows_to_orders_containing_that_item(): void
    {
        $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->itemA->id, 'qty' => 5, 'rate' => 10000]],
        ]);
        $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->itemB->id, 'qty' => 3, 'rate' => 15000]],
        ]);

        $filtered = $this->salesOrderRepository->search(['item_id' => $this->itemA->id]);

        $this->assertCount(1, $filtered->items());
        $this->assertTrue(
            collect($filtered->items())->first()->items->contains(fn ($line) => $line->item_id === $this->itemA->id)
        );
    }

    public function test_existing_customer_and_date_filters_still_work_alongside_item_id(): void
    {
        $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->itemA->id, 'qty' => 1, 'rate' => 10000]],
        ]);

        $results = $this->salesOrderRepository->search([
            'customer_id' => $this->customer->id,
            'item_id' => $this->itemA->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertCount(1, $results->items());
    }
}
