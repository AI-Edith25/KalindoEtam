<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
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
 * A Draft Invoice's line items used to be fully locked (DO price corrections never reached the
 * Invoice sourced from them). Qty/Rate/Tax per existing line can now be corrected before submit
 * too — same no-add/no-remove shape as the Submitted relaxation (InvoiceEditSubmittedTest), but
 * with no stock/GL to reverse-and-repost since a Draft hasn't posted either yet.
 */
class InvoiceEditDraftItemsTest extends TestCase
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
        $this->item = Item::query()->create(['item_code' => 'ITM-1', 'item_name' => 'Semen', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000]);

        $this->seedStock($this->item->id, $this->warehouse->id, 1000);
    }

    protected function draftInvoice(int $qty = 10, float $rate = 10000): Invoice
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $delivery = $this->deliveryService->complete($delivery);

        return $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_rate_edit_on_a_draft_invoice_recomputes_subtotal_and_grand_total(): void
    {
        $invoice = $this->draftInvoice(qty: 10, rate: 10000);
        $line = $invoice->items->first();
        $this->assertEquals(100000, (float) $invoice->subtotal);

        $updated = $this->invoiceService->update($invoice, [
            'items' => [['id' => $line->id, 'rate' => 12500]],
        ]);

        $this->assertSame('draft', $updated->status->value);
        $this->assertEquals(125000, (float) $updated->subtotal);
        $this->assertEquals(125000, (float) $updated->grand_total);
        $this->assertEquals(12500, (float) $updated->items->first()->rate);
    }

    public function test_qty_edit_on_a_draft_invoice_is_also_allowed(): void
    {
        $invoice = $this->draftInvoice(qty: 10, rate: 10000);
        $line = $invoice->items->first();

        $updated = $this->invoiceService->update($invoice, [
            'items' => [['id' => $line->id, 'qty' => 4]],
        ]);

        $this->assertEquals(40000, (float) $updated->subtotal);
        $this->assertEquals(40000, (float) $updated->grand_total);
    }

    public function test_draft_item_rows_cannot_be_added_or_removed(): void
    {
        $invoice = $this->draftInvoice();

        try {
            $this->invoiceService->update($invoice, ['items' => []]);
            $this->fail('Expected removing every line to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('tidak bisa ditambah atau dihapus', $e->getMessage());
        }
    }

    public function test_zero_qty_is_rejected(): void
    {
        $invoice = $this->draftInvoice();
        $line = $invoice->items->first();

        try {
            $this->invoiceService->update($invoice, ['items' => [['id' => $line->id, 'qty' => 0]]]);
            $this->fail('Expected a zero qty to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('harus lebih dari 0', $e->getMessage());
        }
    }

    public function test_no_journal_entry_exists_before_or_after_editing_a_draft_invoice(): void
    {
        $invoice = $this->draftInvoice(qty: 10, rate: 10000);
        $line = $invoice->items->first();

        $this->invoiceService->update($invoice, ['items' => [['id' => $line->id, 'rate' => 15000]]]);

        $this->assertSame(0, \App\Models\JournalEntry::query()->where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)->count());
    }
}
