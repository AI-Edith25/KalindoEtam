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
use App\Services\DeliveryService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryDiscountAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
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
        $this->deliveryService = app(DeliveryService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Widget', 'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
        $this->ppn = Tax::query()->create(['name' => 'PPN 11%', 'code' => 'PPN11', 'type' => TaxType::VAT, 'transaction_type' => TaxTransactionType::SALES, 'rate' => 11, 'is_active' => true]);

        $this->seedStock($this->item->id, $this->warehouse->id, 100, 0);
    }

    public function test_percentage_discount_copies_as_is_to_every_partial_delivery(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id, 'branch_id' => Branch::query()->first()->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 100_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id]],
        ]);
        $this->salesOrderService->approve($order);
        $soItem = $order->fresh()->items->first();

        $delivery1 = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 4]],
        ]);
        $delivery2 = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 6]],
        ]);

        $line1 = $delivery1->items->first();
        $line2 = $delivery2->items->first();

        // 4 * 100.000 = 400.000 gross, 10% = 40.000 discount, net 360.000.
        $this->assertEquals(40_000, (float) $line1->discount_amount);
        $this->assertEquals(360_000, (float) $line1->net_amount);
        $this->assertEquals(39_600, (float) $line1->tax_amount); // 360.000 * 11%

        // 6 * 100.000 = 600.000 gross, 10% = 60.000 discount, net 540.000.
        $this->assertEquals(60_000, (float) $line2->discount_amount);
        $this->assertEquals(540_000, (float) $line2->net_amount);
        $this->assertEquals(59_400, (float) $line2->tax_amount);
    }

    public function test_nominal_discount_is_prorated_by_qty_shipped(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id, 'branch_id' => Branch::query()->first()->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            // SO line: qty 10 @ 100.000 = 1.000.000 gross, Rp 100.000 flat discount.
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 100_000, 'discount_type' => 'amount', 'discount_value' => 100_000]],
        ]);
        $this->salesOrderService->approve($order);
        $soItem = $order->fresh()->items->first();

        // DO1 ships 4 of 10 -> 40% of the 100.000 discount = 40.000.
        $delivery1 = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 4]],
        ]);
        // DO2 ships the remaining 6 of 10 -> 60% = 60.000.
        $delivery2 = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 6]],
        ]);

        $this->assertEquals(40_000, (float) $delivery1->items->first()->discount_amount);
        $this->assertEquals(360_000, (float) $delivery1->items->first()->net_amount);
        $this->assertEquals(60_000, (float) $delivery2->items->first()->discount_amount);
        $this->assertEquals(540_000, (float) $delivery2->items->first()->net_amount);
        $this->assertEquals(100_000, (float) $delivery1->items->first()->discount_amount + (float) $delivery2->items->first()->discount_amount);
    }

    public function test_direct_delivery_line_accepts_a_manual_discount(): void
    {
        $delivery = $this->deliveryService->create([
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 50_000, 'discount_type' => 'amount', 'discount_value' => 10_000, 'tax_id' => $this->ppn->id]],
        ]);

        $line = $delivery->items->first();
        $this->assertEquals(10_000, (float) $line->discount_amount);
        $this->assertEquals(240_000, (float) $line->net_amount); // 250.000 - 10.000
        $this->assertEquals(26_400, (float) $line->tax_amount); // 240.000 * 11%
    }
}
