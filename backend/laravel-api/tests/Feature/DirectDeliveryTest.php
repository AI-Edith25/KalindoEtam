<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Enums\WarehouseType;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct/no-Sales-Order Delivery *creation* was removed 2026-10-08 — every new Delivery now has
 * to come from at least one Sales Order (DeliveryService::create()). This file used to cover that
 * creation path end to end; it now covers the opposite: creation is rejected, and an already-
 * existing direct Delivery (built directly here, the way one from before this change would look)
 * stays fully usable — DeliveryService::update()/updateComplete()/complete() never special-cased
 * creation vs already-existing, so nothing downstream of creation needed to change.
 */
class DirectDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected DeliveryService $deliveryService;

    protected InvoiceService $invoiceService;

    protected StockLedgerService $stockLedgerService;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->stockLedgerService = app(StockLedgerService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create(['item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000]);

        $this->seedStock($this->item->id, $this->warehouse->id, 100);
    }

    public function test_create_without_any_sales_order_is_rejected(): void
    {
        $this->expectException(BusinessException::class);

        $this->deliveryService->create([
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 12, 'rate' => 9500]],
        ]);
    }

    /** Builds a pre-existing direct Delivery (Pending, no sales_order_id) the way one created
        before 2026-10-08 would look — not through DeliveryService, which can no longer produce one. */
    private function makeExistingDirectDelivery(float $qty = 12, float $rate = 9500): Delivery
    {
        $delivery = Delivery::query()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        DeliveryItem::query()->create([
            'delivery_id' => $delivery->id,
            'item_id' => $this->item->id,
            'item_code' => $this->item->item_code,
            'item_name' => $this->item->item_name,
            'uom' => 'Pcs',
            'uom_factor' => 1,
            'rate' => $rate,
            'qty' => $qty,
            'qty_category' => 'unit',
            'amount' => $qty * $rate,
            'net_amount' => $qty * $rate,
            'tax_amount' => 0,
        ]);

        return $delivery->fresh(['items']);
    }

    public function test_an_existing_direct_delivery_can_still_be_completed(): void
    {
        $delivery = $this->makeExistingDirectDelivery(qty: 12);

        $this->assertNull($delivery->sales_order_id);

        $delivery = $this->deliveryService->complete($delivery);

        $this->assertEquals('complete', $delivery->status->value);
        $this->assertEquals(100 - 12, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
    }

    public function test_an_existing_direct_delivery_can_still_be_edited_while_pending(): void
    {
        $delivery = $this->makeExistingDirectDelivery(qty: 12);

        $delivery = $this->deliveryService->update($delivery, [
            'items' => [['item_id' => $this->item->id, 'qty' => 20, 'rate' => 9500]],
        ]);

        $this->assertEquals(20, (float) $delivery->items->first()->qty);
    }

    public function test_invoice_can_still_be_generated_from_an_existing_completed_direct_delivery(): void
    {
        $delivery = $this->makeExistingDirectDelivery(qty: 10);
        $delivery = $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertNull($invoice->sales_order_id);
        $this->assertEquals(10, (float) $invoice->items->first()->qty);
    }
}
