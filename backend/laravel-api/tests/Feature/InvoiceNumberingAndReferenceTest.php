<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\SalesPerson;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UAT review (2026-08-12): SI/KE/#####/MM/YYYY (Goods) / TR/KE/#####/MM/YYYY
 * (Transportation) numbering, plus Reference 1 auto-prefill from the anchor
 * Sales Order for Goods invoices (never for Transportation, which has none).
 */
class InvoiceNumberingAndReferenceTest extends TestCase
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
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        app(StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 100,
            postingDatetime: now(),
        );
    }

    protected function submittedDeliveryWithSalesPerson(SalesPerson $salesPerson): \App\Models\Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'sales_person_id' => $salesPerson->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 10000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 10]],
        ]);

        return $this->deliveryService->submit($delivery);
    }

    public function test_goods_invoice_gets_si_ke_number_and_prefills_sales_person_and_reference_1_from_sales_order(): void
    {
        $salesPerson = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);
        $delivery = $this->submittedDeliveryWithSalesPerson($salesPerson);
        $soNumber = $delivery->salesOrder->document_number;

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertMatchesRegularExpression('#^SI/KE/00001/\d{2}/\d{4}$#', $invoice->document_number);
        $this->assertSame(now()->format('m'), substr($invoice->document_number, -7, 2));
        $this->assertSame(now()->format('Y'), substr($invoice->document_number, -4));
        $this->assertSame($salesPerson->id, $invoice->sales_person_id);
        $this->assertSame($soNumber, $invoice->reference_1);
        $this->assertNull($invoice->reference_2);
    }

    public function test_goods_invoice_number_never_resets_across_generations(): void
    {
        $salesPerson = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);

        $first = $this->invoiceService->create([
            'delivery_ids' => [$this->submittedDeliveryWithSalesPerson($salesPerson)->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $second = $this->invoiceService->create([
            'delivery_ids' => [$this->submittedDeliveryWithSalesPerson($salesPerson)->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertStringContainsString('/00001/', $first->document_number);
        $this->assertStringContainsString('/00002/', $second->document_number);
    }

    public function test_transportation_invoice_gets_tr_ke_number_with_no_reference_1_auto_prefill(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => 'transportation',
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['description' => 'Freight Balikpapan-Jakarta', 'qty' => 1, 'rate' => 500000]],
        ]);

        $this->assertMatchesRegularExpression('#^TR/KE/00001/\d{2}/\d{4}$#', $invoice->document_number);
        $this->assertNull($invoice->reference_1);
        $this->assertNull($invoice->sales_person_id);
    }
}
