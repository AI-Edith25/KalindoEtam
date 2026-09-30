<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\SalesOrder;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Complete Delivery used to be fully locked (DeliveryService::assertDraft()). This relaxation
 * (updateComplete()/reverseDeliveryStock()/postDeliveryStock()) allows header fields and Qty/Rate/
 * Tax per line to be corrected, including on an already-invoiced line — but never removing a line
 * that's already been invoiced (invoice_items.delivery_item_id is restrictOnDelete).
 */
class DeliveryEditCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected DeliveryService $deliveryService;

    protected InvoiceService $invoiceService;

    protected StockLedgerService $stockLedgerService;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Item $item;

    protected Item $otherItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
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
        $this->otherItem = Item::query()->create(['item_code' => 'ITM-2', 'item_name' => 'Gadget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 5000]);

        $this->seedStock($this->item->id, $this->warehouse->id, 1000);
        $this->seedStock($this->otherItem->id, $this->warehouse->id, 1000);
    }

    protected function completeDelivery(int $qty = 10, float $rate = 10000, int $orderedQty = 20): Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => $orderedQty, 'rate' => $rate],
                ['item_id' => $this->otherItem->id, 'qty' => $orderedQty, 'rate' => 5000],
            ],
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

        return $this->deliveryService->complete($delivery);
    }

    public function test_header_fields_are_editable_on_a_complete_delivery(): void
    {
        $delivery = $this->completeDelivery();

        $updated = $this->deliveryService->update($delivery, [
            'fleet' => 'B 1234 XYZ',
            'driver' => 'Budi',
            'attention' => 'Pak Joko',
            'tel' => '0812345',
            'remarks' => 'Corrected after complete',
            'lock_version' => $delivery->lock_version,
        ]);

        $this->assertSame('complete', $updated->status->value, 'still complete — edit never reverts status');
        $this->assertSame('B 1234 XYZ', $updated->fleet);
        $this->assertSame('Pak Joko', $updated->attention);
        $this->assertSame('Corrected after complete', $updated->remarks);
        $this->assertSame(2, $updated->lock_version);
    }

    public function test_qty_edit_on_a_complete_delivery_line_reverses_and_reposts_stock(): void
    {
        $delivery = $this->completeDelivery(qty: 10, rate: 10000);
        $line = $delivery->items->first();
        $soItemId = $line->sales_order_item_id;

        $balanceBefore = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);
        $this->assertEquals(990, $balanceBefore, 'seeded 1000, delivered 10');

        $updated = $this->deliveryService->update($delivery, [
            'lock_version' => $delivery->lock_version,
            'items' => [
                ['id' => $line->id, 'sales_order_item_id' => $soItemId, 'qty' => 15],
            ],
        ]);

        $newLine = $updated->items->first();
        $this->assertEquals(15, (float) $newLine->qty);
        $this->assertEquals(15, (float) $newLine->salesOrderItem()->first()->delivered_qty ?? $newLine->qty);

        $balanceAfter = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);
        $this->assertEquals(985, $balanceAfter, 'stock reflects the new qty (15), not stacked on top of the old (10)');

        $soItem = \App\Models\SalesOrderItem::query()->findOrFail($soItemId);
        $this->assertEquals(15, $soItem->delivered_qty, 'delivered_qty reflects the new qty, reverse-then-repost, not double-counted');
    }

    public function test_rate_and_tax_are_editable_on_an_already_invoiced_line(): void
    {
        $delivery = $this->completeDelivery(qty: 10, rate: 10000);
        $line = $delivery->items->first();

        $invoice = $this->invoiceService->create([
            'invoice_type' => 'goods',
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->assertEquals(10000, (float) $invoice->items->first()->rate, 'Invoice snapshot taken at creation time');

        $updated = $this->deliveryService->update($delivery->fresh(), [
            'lock_version' => $delivery->lock_version,
            'items' => [
                ['id' => $line->id, 'sales_order_item_id' => $line->sales_order_item_id, 'qty' => 10, 'rate' => 9000],
            ],
        ]);

        $this->assertEquals(9000, (float) $updated->items->first()->rate, 'DO rate is independently editable even once invoiced');
        $this->assertEquals(10000, (float) $invoice->fresh()->items->first()->rate, 'the already-issued Invoice is never touched by a DO edit');
    }

    public function test_removing_an_already_invoiced_line_is_rejected(): void
    {
        $delivery = $this->completeDelivery(qty: 10, rate: 10000);
        $line = $delivery->items->first();

        $this->invoiceService->create([
            'invoice_type' => 'goods',
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        try {
            $this->deliveryService->update($delivery->fresh(), [
                'lock_version' => $delivery->lock_version,
                'items' => [
                    ['sales_order_item_id' => $delivery->fresh()->items->first()->sales_order_item_id, 'qty' => 5],
                ],
            ]);
            $this->fail('Expected removing an already-invoiced line to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('sudah di-invoice', $e->getMessage());
        }

        $this->assertSame(1, $delivery->fresh()->items()->count(), 'nothing was deleted — the whole edit rolled back');
    }

    public function test_a_new_line_can_be_added_within_its_own_sales_order_items_outstanding(): void
    {
        $delivery = $this->completeDelivery(qty: 10, rate: 10000);
        $line = $delivery->items->first();
        $otherSoItem = $delivery->salesOrder->items()->where('item_id', $this->otherItem->id)->firstOrFail();

        $updated = $this->deliveryService->update($delivery, [
            'lock_version' => $delivery->lock_version,
            'items' => [
                ['id' => $line->id, 'sales_order_item_id' => $line->sales_order_item_id, 'qty' => 10],
                ['sales_order_item_id' => $otherSoItem->id, 'qty' => 4],
            ],
        ]);

        $this->assertCount(2, $updated->items);
        $this->assertEquals(4, (float) $otherSoItem->fresh()->delivered_qty);
    }

    public function test_stale_lock_version_is_rejected_with_409(): void
    {
        $delivery = $this->completeDelivery();

        $this->deliveryService->update($delivery, ['remarks' => 'First edit', 'lock_version' => $delivery->lock_version]);

        try {
            $this->deliveryService->update($delivery, ['remarks' => 'Second, stale edit', 'lock_version' => $delivery->lock_version]);
            $this->fail('Expected a stale lock_version to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('sudah diubah oleh pengguna lain', $e->getMessage());
        }
    }

    public function test_audit_log_records_the_edit_with_field_level_changes(): void
    {
        $delivery = $this->completeDelivery();

        $this->deliveryService->update($delivery, ['fleet' => 'B 9999 ZZ', 'lock_version' => $delivery->lock_version]);

        $log = AuditLog::query()->where('module', 'delivery')->where('action', 'updated')->latest('created_at')->firstOrFail();
        $this->assertSame($delivery->id, $log->properties['subject_id']);
        $this->assertSame('B 9999 ZZ', $log->properties['changes']['fleet']['new']);
    }
}
