<?php

namespace Tests\Feature;

use App\Enums\TaxCalculationMode;
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
use App\Services\InvoiceService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A standalone Delivery with no source Sales Order — items/qty/rate/tax are
 * typed directly instead of inherited from a Sales Order line. Same shape as
 * GoodsReceiptTest's direct-receipt coverage. Lets a user record and tax a
 * delivery that never went through the Sales Order workflow.
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

    protected function makeTax(): Tax
    {
        return Tax::query()->create([
            'code' => 'PPN11', 'name' => 'PPN 11%', 'type' => TaxType::VAT,
            'transaction_type' => TaxTransactionType::SALES, 'rate' => 11, 'is_active' => true,
            'calculation_mode' => TaxCalculationMode::EXCLUSIVE,
        ]);
    }

    public function test_creates_and_completes_a_direct_delivery_with_manual_tax(): void
    {
        $tax = $this->makeTax();

        $delivery = $this->deliveryService->create([
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 12, 'rate' => 9500, 'tax_id' => $tax->id]],
        ]);

        $this->assertNull($delivery->sales_order_id);
        $line = $delivery->items->first();
        $this->assertNull($line->sales_order_item_id);
        $this->assertEquals(12, (float) $line->qty);
        $this->assertEquals($tax->id, $line->tax_id);
        $this->assertEqualsWithDelta(12540.0, (float) $line->tax_amount, 0.01); // 12 * 9500 * 11%

        $delivery = $this->deliveryService->complete($delivery->fresh());

        $this->assertEquals('complete', $delivery->status->value);
        $this->assertEquals(100 - 12, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
    }

    public function test_direct_delivery_line_defaults_to_no_tax_when_omitted(): void
    {
        $delivery = $this->deliveryService->create([
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 9500]],
        ]);

        $line = $delivery->items->first();
        $this->assertNull($line->tax_id);
        $this->assertEquals(0, (float) $line->tax_amount);
    }

    public function test_invoice_can_be_generated_from_a_completed_direct_delivery(): void
    {
        $tax = $this->makeTax();

        $delivery = $this->deliveryService->create([
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 9500, 'tax_id' => $tax->id]],
        ]);
        $delivery = $this->deliveryService->complete($delivery->fresh());

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertNull($invoice->sales_order_id);
        $invoiceItem = $invoice->items->first();
        $this->assertEquals($tax->id, $invoiceItem->tax_id);
        $this->assertEqualsWithDelta((float) $delivery->fresh()->items->first()->tax_amount, (float) $invoiceItem->tax_amount, 0.01);
    }
}
