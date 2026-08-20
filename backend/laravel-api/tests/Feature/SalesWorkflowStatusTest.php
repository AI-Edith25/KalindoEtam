<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Http\Resources\DeliveryResource;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ReceiptEntry;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the four checks the SO/DO/SI status-model rework's Verifikasi
 * section calls out explicitly: stock only moves on Delivery Complete (and
 * not twice), a Delivery can't be created from a non-Approved Sales Order,
 * Invoice display_status tracks payment, and DO tax is computed per-document
 * from the Sales Order's rate.
 */
class SalesWorkflowStatusTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
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
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        $this->stockLedgerService->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 100,
            postingDatetime: now(),
        );
    }

    protected function approvedSalesOrder(int $qty = 10, float $rate = 10000, ?string $taxId = null)
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate, 'tax_id' => $taxId]],
        ]);
        $this->approveDocument($salesOrder);

        return $this->salesOrderService->approve($salesOrder);
    }

    public function test_stock_is_unaffected_by_creating_a_pending_delivery_and_only_moves_on_complete(): void
    {
        $salesOrder = $this->approvedSalesOrder(qty: 10);
        $balanceBefore = $this->stockLedgerService->peekBalance($this->item->id, $this->warehouse->id);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 4]],
        ]);

        $this->assertSame('pending', $delivery->status->value);
        $this->assertEquals($balanceBefore, $this->stockLedgerService->peekBalance($this->item->id, $this->warehouse->id));

        $completed = $this->deliveryService->complete($delivery);

        $this->assertSame('complete', $completed->status->value);
        $this->assertEquals($balanceBefore - 4, $this->stockLedgerService->peekBalance($this->item->id, $this->warehouse->id));
    }

    public function test_completing_a_delivery_twice_is_rejected_without_double_decrementing_stock(): void
    {
        $salesOrder = $this->approvedSalesOrder(qty: 10);
        $balanceBefore = $this->stockLedgerService->peekBalance($this->item->id, $this->warehouse->id);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 3]],
        ]);
        $delivery = $this->deliveryService->complete($delivery);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        try {
            $this->deliveryService->complete($delivery);
        } finally {
            $this->assertEquals($balanceBefore - 3, $this->stockLedgerService->peekBalance($this->item->id, $this->warehouse->id));
        }
    }

    public function test_delivery_creation_is_rejected_when_the_sales_order_is_not_approved(): void
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 10000]],
        ]);
        $this->assertSame('submitted', $salesOrder->status->value); // awaiting approval, not yet approved

        $this->expectException(BusinessException::class);

        $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);
    }

    public function test_invoice_display_status_tracks_unpaid_partial_paid_as_payments_are_allocated(): void
    {
        $salesOrder = $this->approvedSalesOrder(qty: 10, rate: 10000); // grand_total 100000
        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 10]],
        ]);
        $delivery = $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice = $this->invoiceService->submit($invoice);

        $this->assertSame('unpaid', $this->displayStatus($invoice->id));

        $cashAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $receiptEntry = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $cashAccount->id,
            'total_amount' => 40000,
            'allocated_amount' => 0,
        ])->submit();

        app(PaymentAllocationService::class)->allocateBatch($receiptEntry, [
            ['accounts_receivable_id' => $invoice->accountsReceivable->id, 'amount' => 40000],
        ]);

        $this->assertSame('partial', $this->displayStatus($invoice->id));

        $receiptEntry2 = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $cashAccount->id,
            'total_amount' => 60000,
            'allocated_amount' => 0,
        ])->submit();

        app(PaymentAllocationService::class)->allocateBatch($receiptEntry2, [
            ['accounts_receivable_id' => $invoice->accountsReceivable->id, 'amount' => 60000],
        ]);

        $this->assertSame('paid', $this->displayStatus($invoice->id));
    }

    protected function displayStatus(string $invoiceId): string
    {
        $invoice = \App\Models\Invoice::query()->with('accountsReceivable')->findOrFail($invoiceId);

        return (new \App\Http\Resources\InvoiceResource($invoice))->toArray(request())['display_status'];
    }

    public function test_delivery_tax_amount_is_computed_from_the_sales_order_rate_on_a_partial_shipment(): void
    {
        $tax = Tax::query()->create(['code' => 'PPN11', 'name' => 'PPN 11%', 'type' => TaxType::VAT, 'transaction_type' => TaxTransactionType::SALES, 'rate' => 11, 'is_active' => true]);
        $salesOrder = $this->approvedSalesOrder(qty: 10, rate: 10000, taxId: $tax->id); // full SO amount 100000

        // Only 4 of the 10 ordered units go out on this Delivery — a partial shipment.
        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 4]],
        ]);
        $delivery = $this->deliveryService->complete($delivery);
        $delivery->load(['items.tax']);

        $resource = (new DeliveryResource($delivery))->toArray(request());

        // Tax on this partial shipment's own 40000 (4 x 10000), not the full Sales Order's 100000.
        $this->assertEquals($tax->id, $resource['tax_id']);
        $this->assertEquals(4400.0, (float) $resource['tax_amount']);
    }
}
