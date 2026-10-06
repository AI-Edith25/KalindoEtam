<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\SalesOrder;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\ItemService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A Sales Order line can be in one of its Item's UOMs; stock, FIFO cost and COGS are all in base units. */
class SalesOrderUomTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected UnitOfMeasurement $dus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $kg = UnitOfMeasurement::query()->create(['name' => 'KG']);
        $this->dus = UnitOfMeasurement::query()->create(['name' => 'DUS']);
        $this->item = app(ItemService::class)->create([
            'item_code' => 'PKU-1', 'item_name' => 'Paku', 'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id,
            'uom_id' => $kg->id, 'standard_rate' => 1000,
            'uoms' => [['uom_id' => $this->dus->id, 'conversion_factor' => 25]],
        ]);

        // 100 KG at Rp800 per KG.
        $this->seedStock($this->item->id, $this->warehouse->id, 100, 800);
    }

    protected function order(array $line, bool $withWarehouse = true): SalesOrder
    {
        return $this->salesOrderService->create(array_filter([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $withWarehouse ? $this->warehouse->id : null,
            'order_date' => now()->toDateString(),
            'items' => [array_merge(['item_id' => $this->item->id], $line)],
        ]));
    }

    public function test_so_line_uom_and_factor_are_saved_and_amount_is_unconverted(): void
    {
        $salesOrder = $this->order(['uom_id' => $this->dus->id, 'qty' => 3, 'rate' => 25000]);

        $line = $salesOrder->items->first();
        $this->assertSame($this->dus->id, $line->uom_id);
        $this->assertEquals(25, (float) $line->uom_factor);
        $this->assertEquals(75000, (float) $line->amount);
    }

    public function test_stock_check_compares_base_units(): void
    {
        // 3 DUS = 75 KG of 100 KG — fine.
        $this->order(['uom_id' => $this->dus->id, 'qty' => 3, 'rate' => 25000]);

        // Sales Orders don't reserve stock, so 2 more DUS (50 KG) is still allowed against 100 KG physical.
        $this->order(['uom_id' => $this->dus->id, 'qty' => 2, 'rate' => 25000]);

        // 5 DUS = 125 KG exceeds the 100 KG physical — blocked, compared in base units.
        $this->expectException(BusinessException::class);
        $this->order(['uom_id' => $this->dus->id, 'qty' => 5, 'rate' => 25000]);
    }

    public function test_delivery_moves_base_stock_and_invoice_cogs_uses_base_cost(): void
    {
        $salesOrder = $this->order(['uom_id' => $this->dus->id, 'qty' => 3, 'rate' => 25000], withWarehouse: false);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 2]],
        ]);
        $this->assertSame('DUS', $delivery->items->first()->uom);

        $this->deliveryService->complete($delivery);

        // 2 DUS = 50 KG out of 100; SO delivered_qty stays in DUS.
        $this->assertEquals(50, app(StockLedgerService::class)->getCurrentBalance($this->item->id, $this->warehouse->id));
        $this->assertEquals(2, $salesOrder->items()->first()->delivered_qty);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $line = $invoice->items->first();
        $this->assertEquals(50000, (float) $line->amount);
        $this->assertEquals(800, (float) $line->unit_cost);
        // COGS = 50 KG × Rp800, not 2 × Rp800.
        $this->assertEquals(40000, (float) $line->cost_amount);
    }

    public function test_delivery_beyond_stock_in_base_units_is_rejected(): void
    {
        $salesOrder = $this->order(['uom_id' => $this->dus->id, 'qty' => 5, 'rate' => 25000], withWarehouse: false);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        // 5 DUS = 125 KG, only 100 KG on hand.
        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);

        $this->expectException(BusinessException::class);
        $this->deliveryService->complete($delivery);
    }

    public function test_uom_not_belonging_to_the_item_is_rejected(): void
    {
        $ton = UnitOfMeasurement::query()->create(['name' => 'TON']);

        $this->expectException(BusinessException::class);
        $this->order(['uom_id' => $ton->id, 'qty' => 1, 'rate' => 1000]);
    }
}
