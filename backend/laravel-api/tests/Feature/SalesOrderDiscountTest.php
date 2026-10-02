<?php

namespace Tests\Feature;

use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected Tax $ppn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Widget', 'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
        $this->ppn = Tax::query()->create(['name' => 'PPN 11%', 'code' => 'PPN11', 'type' => TaxType::VAT, 'transaction_type' => TaxTransactionType::SALES, 'rate' => 11, 'is_active' => true]);

        $this->seedStock($this->item->id, $this->warehouse->id, 100, 0);
    }

    public function test_reference_example_percentage_discount_then_tax_on_net(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => Branch::query()->first()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10_000_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id],
            ],
        ]);

        $line = $order->items->first();
        $this->assertEquals(10_000_000, (float) $line->amount);
        $this->assertEquals(1_000_000, (float) $line->discount_amount);
        $this->assertEquals(9_000_000, (float) $line->net_amount);
        $this->assertEquals(990_000, (float) $line->tax_amount);

        $this->assertEquals(10_000_000, (float) $order->total_amount);
        $this->assertEquals(1_000_000, (float) $order->total_discount);
        $this->assertEquals(9_000_000, (float) $order->tax_base);
        $this->assertEquals(990_000, (float) $order->tax_amount);
        $this->assertEquals(9_990_000, (float) $order->grand_total);
    }

    public function test_line_with_no_discount_behaves_exactly_as_before(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => Branch::query()->first()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 2, 'rate' => 500_000, 'tax_id' => $this->ppn->id],
            ],
        ]);

        $line = $order->items->first();
        $this->assertEquals(0, (float) $line->discount_amount);
        $this->assertEquals(1_000_000, (float) $line->net_amount);
        $this->assertEquals(110_000, (float) $line->tax_amount);
        $this->assertEquals(1_110_000, (float) $order->grand_total);
    }

    public function test_item_with_no_tax_is_never_taxed_regardless_of_discount(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => Branch::query()->first()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 1, 'rate' => 100_000, 'discount_type' => 'amount', 'discount_value' => 20_000],
            ],
        ]);

        $this->assertEquals(0, (float) $order->tax_amount);
        $this->assertEquals(80_000, (float) $order->grand_total);
    }

    public function test_updating_a_lines_discount_recomputes_header_totals(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => Branch::query()->first()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 100_000, 'tax_id' => $this->ppn->id]],
        ]);

        $order = $this->salesOrderService->update($order, [
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 100_000, 'discount_type' => 'amount', 'discount_value' => 10_000, 'tax_id' => $this->ppn->id]],
        ]);

        $this->assertEquals(10_000, (float) $order->total_discount);
        $this->assertEquals(90_000, (float) $order->tax_base);
        $this->assertEquals(9_900, (float) $order->tax_amount);
        $this->assertEquals(99_900, (float) $order->grand_total);
    }
}
