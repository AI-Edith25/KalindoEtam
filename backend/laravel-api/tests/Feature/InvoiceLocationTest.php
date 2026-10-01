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
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice's own "Location" field (location_warehouse_id) — purely a printed/displayed value,
 * independent of warehouse_id (Direct Goods' stock-consumption discriminator, see Invoice::
 * isDirectGoods()). Defaults from the anchor Delivery/the picked warehouse at creation, editable
 * afterward at any status with zero stock/accounting side effects.
 */
class InvoiceLocationTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
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
        $this->invoiceService = app(InvoiceService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);

        $this->seedStock($this->item->id, $this->warehouse->id, 100);
    }

    protected function submittedDelivery(): \App\Models\Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 10000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 10]],
        ]);

        return $this->deliveryService->complete($delivery);
    }

    public function test_a_delivery_based_invoice_defaults_its_location_to_the_deliverys_warehouse(): void
    {
        $delivery = $this->submittedDelivery();

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertSame($this->warehouse->id, $invoice->location_warehouse_id);
    }

    public function test_a_delivery_based_invoice_location_can_be_overridden_at_creation(): void
    {
        $other = Warehouse::query()->create(['name' => 'Branch WH', 'code' => 'WH2', 'warehouse_type' => WarehouseType::MAIN]);
        $delivery = $this->submittedDelivery();

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'location_warehouse_id' => $other->id,
        ]);

        $this->assertSame($other->id, $invoice->location_warehouse_id);
    }

    public function test_a_direct_goods_invoice_defaults_its_location_to_its_own_warehouse(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => 'goods',
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000]],
        ]);

        $this->assertSame($this->warehouse->id, $invoice->location_warehouse_id);
    }

    public function test_location_can_be_edited_on_a_draft_invoice_without_touching_stock(): void
    {
        $other = Warehouse::query()->create(['name' => 'Branch WH', 'code' => 'WH2', 'warehouse_type' => WarehouseType::MAIN]);
        $delivery = $this->submittedDelivery();
        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $updated = $this->invoiceService->update($invoice, ['location_warehouse_id' => $other->id]);

        $this->assertSame($other->id, $updated->location_warehouse_id);
        $this->assertFalse($updated->isDirectGoods(), 'editing location must never turn a Delivery-based invoice into a Direct one');
    }

    public function test_location_can_be_edited_on_a_submitted_invoice(): void
    {
        $other = Warehouse::query()->create(['name' => 'Branch WH', 'code' => 'WH2', 'warehouse_type' => WarehouseType::MAIN]);
        $delivery = $this->submittedDelivery();
        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice = $this->invoiceService->submit($invoice);

        $updated = $this->invoiceService->update($invoice, ['location_warehouse_id' => $other->id, 'lock_version' => $invoice->lock_version]);

        $this->assertSame($other->id, $updated->location_warehouse_id);
    }
}
