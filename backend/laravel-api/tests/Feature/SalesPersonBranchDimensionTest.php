<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSalesOrderRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\SalesPerson;
use App\Models\UnitOfMeasurement;
use App\Repositories\SalesOrderRepository;
use App\Services\BranchService;
use App\Services\SalesOrderService;
use App\Services\SalesPersonService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Task 3 — Sales Person and Branch dimensions on Sales Order + Sales Report. */
class SalesPersonBranchDimensionTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected SalesOrderRepository $salesOrderRepository;
    protected SalesPersonService $salesPersonService;
    protected BranchService $branchService;
    protected Customer $customer;
    protected Item $item;
    protected Branch $branchA;
    protected Branch $branchB;
    protected SalesPerson $salesPersonA;
    protected SalesPerson $salesPersonB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->salesOrderRepository = app(SalesOrderRepository::class);
        $this->salesPersonService = app(SalesPersonService::class);
        $this->branchService = app(BranchService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branchA = Branch::query()->create(['company_id' => $company->id, 'name' => 'Head Office', 'code' => 'HQ', 'is_head_office' => true]);
        $this->branchB = Branch::query()->create(['company_id' => $company->id, 'name' => 'Balikpapan', 'code' => 'BPN']);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);

        $this->salesPersonA = $this->salesPersonService->create(['code' => 'SP001', 'name' => 'Budi']);
        $this->salesPersonB = $this->salesPersonService->create(['code' => 'SP002', 'name' => 'Siti']);
    }

    public function test_sales_person_crud_round_trip(): void
    {
        $salesPerson = $this->salesPersonService->create(['code' => 'SP999', 'name' => 'Andi', 'phone' => '0812', 'email' => 'andi@example.com']);
        $this->assertDatabaseHas('sales_persons', ['code' => 'SP999', 'name' => 'Andi', 'is_active' => true]);

        $updated = $this->salesPersonService->update($salesPerson, ['name' => 'Andi Wijaya', 'is_active' => false]);
        $this->assertSame('Andi Wijaya', $updated->name);
        $this->assertFalse($updated->is_active);

        $this->salesPersonService->delete($updated);
        $this->assertSoftDeleted('sales_persons', ['id' => $salesPerson->id]);
    }

    public function test_branch_is_active_field_round_trips(): void
    {
        // Branch::create() doesn't set is_active explicitly, so the in-memory model only reflects
        // the DB-level default(true) after a fresh read — same as the pre-existing is_head_office column.
        $this->assertTrue($this->branchB->fresh()->is_active);

        $updated = $this->branchService->update($this->branchB, ['is_active' => false]);
        $this->assertFalse($updated->fresh()->is_active);
    }

    public function test_sales_order_create_persists_sales_person_and_branch(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'sales_person_id' => $this->salesPersonA->id,
            'branch_id' => $this->branchA->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 2, 'rate' => 10000]],
        ]);

        $this->assertSame($this->salesPersonA->id, $order->sales_person_id);
        $this->assertSame($this->branchA->id, $order->branch_id);
        $this->assertSame($this->salesPersonA->id, $order->salesPerson->id);
        $this->assertSame($this->branchA->id, $order->branch->id);
    }

    public function test_sales_order_create_allows_null_sales_person(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branchA->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ]);

        $this->assertNull($order->sales_person_id);
        $this->assertSame($this->branchA->id, $order->branch_id);
    }

    public function test_search_filters_by_sales_person_id(): void
    {
        $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'sales_person_id' => $this->salesPersonA->id,
            'branch_id' => $this->branchA->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ]);
        $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'sales_person_id' => $this->salesPersonB->id,
            'branch_id' => $this->branchA->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ]);

        $filtered = $this->salesOrderRepository->search(['sales_person_id' => $this->salesPersonA->id]);

        $this->assertCount(1, $filtered->items());
        $this->assertSame($this->salesPersonA->id, collect($filtered->items())->first()->sales_person_id);
    }

    public function test_search_filters_by_branch_id(): void
    {
        $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branchA->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ]);
        $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branchB->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ]);

        $filtered = $this->salesOrderRepository->search(['branch_id' => $this->branchB->id]);

        $this->assertCount(1, $filtered->items());
        $this->assertSame($this->branchB->id, collect($filtered->items())->first()->branch_id);
    }

    public function test_store_request_requires_branch_but_not_sales_person(): void
    {
        $rules = (new StoreSalesOrderRequest())->rules();

        $withoutBranch = Validator::make([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ], $rules);
        $this->assertTrue($withoutBranch->fails());
        $this->assertArrayHasKey('branch_id', $withoutBranch->errors()->toArray());

        $withBranchOnly = Validator::make([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branchA->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ], $rules);
        $this->assertFalse($withBranchOnly->fails());
    }
}
