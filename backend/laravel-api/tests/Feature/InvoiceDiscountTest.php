<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
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
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Branch $branch;
    protected Item $item;
    protected Tax $ppn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Widget', 'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
        $this->ppn = Tax::query()->create(['name' => 'PPN 11%', 'code' => 'PPN11', 'type' => TaxType::VAT, 'transaction_type' => TaxTransactionType::SALES, 'rate' => 11, 'is_active' => true]);

        $this->seedStock($this->item->id, $this->warehouse->id, 100, 0);
    }

    public function test_goods_invoice_inherits_discount_from_its_delivery_lines(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id, 'branch_id' => $this->branch->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10_000_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id]],
        ]);
        $this->salesOrderService->approve($order);
        $soItem = $order->fresh()->items->first();

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 1]],
        ]);
        $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $line = $invoice->items->first();
        $this->assertEquals(1_000_000, (float) $line->discount_amount);
        $this->assertEquals(9_000_000, (float) $line->net_amount);
        $this->assertEquals(990_000, (float) $line->tax_amount);

        $this->assertEquals(10_000_000, (float) $invoice->subtotal);
        $this->assertEquals(1_000_000, (float) $invoice->discount_amount);
        $this->assertEquals(9_000_000, (float) $invoice->tax_base);
        $this->assertEquals(990_000, (float) $invoice->tax_amount);
        $this->assertEquals(9_990_000, (float) $invoice->grand_total);
    }

    public function test_direct_goods_invoice_computes_tax_on_net_per_line(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::GOODS->value,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => $this->branch->id,
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10_000_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id]],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertEquals(9_990_000, (float) $invoice->grand_total);
        $this->assertEquals(990_000, (float) $invoice->tax_amount);
    }

    public function test_transportation_invoice_computes_per_line_discount_and_per_line_tax(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'items' => [
                ['description' => 'Ongkos Angkut A', 'qty' => 1, 'rate' => 10_000_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id],
                // Non-VAT line — never taxed regardless of discount.
                ['description' => 'Ongkos Angkut B', 'qty' => 1, 'rate' => 1_000_000, 'discount_type' => 'amount', 'discount_value' => 100_000],
            ],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        [$lineA, $lineB] = $invoice->items;
        $this->assertEquals(990_000, (float) $lineA->tax_amount);
        $this->assertEquals(0, (float) $lineB->tax_amount);

        // Subtotal 11.000.000, discount 1.100.000, tax_base 9.900.000, tax 990.000, grand 10.890.000.
        $this->assertEquals(11_000_000, (float) $invoice->subtotal);
        $this->assertEquals(1_100_000, (float) $invoice->discount_amount);
        $this->assertEquals(9_900_000, (float) $invoice->tax_base);
        $this->assertEquals(990_000, (float) $invoice->tax_amount);
        $this->assertEquals(10_890_000, (float) $invoice->grand_total);
    }

    public function test_hundred_percent_discount_line_produces_zero_tax_and_zero_net(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'items' => [['description' => 'Free service', 'qty' => 1, 'rate' => 500_000, 'discount_type' => 'percentage', 'discount_value' => 100, 'tax_id' => $this->ppn->id]],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $line = $invoice->items->first();
        $this->assertEquals(500_000, (float) $line->discount_amount);
        $this->assertEquals(0, (float) $line->net_amount);
        $this->assertEquals(0, (float) $line->tax_amount);
        $this->assertEquals(0, (float) $invoice->grand_total);
    }

    public function test_editing_a_submitted_invoice_lines_discount_recomputes_and_reposts(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::GOODS->value,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => $this->branch->id,
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 1_000_000, 'tax_id' => $this->ppn->id]],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->invoiceService->submit($invoice);
        $lineId = $invoice->items->first()->id;

        $updated = $this->invoiceService->update($invoice, [
            'items' => [['id' => $lineId, 'discount_type' => 'amount', 'discount_value' => 100_000, 'tax_id' => $this->ppn->id]],
        ]);

        $line = $updated->items->first();
        $this->assertEquals(100_000, (float) $line->discount_amount);
        $this->assertEquals(900_000, (float) $line->net_amount);
        $this->assertEquals(99_000, (float) $line->tax_amount);
        $this->assertEquals(999_000, (float) $updated->grand_total);

        $ar = $updated->accountsReceivable;
        $this->assertEquals(999_000, (float) $ar->amount);
    }
}
