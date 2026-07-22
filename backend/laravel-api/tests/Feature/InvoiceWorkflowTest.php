<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AccountsReceivable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Models\ReceiptEntry;
use App\Services\AccountsReceivableService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected AccountsReceivableService $accountsReceivableService;
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
        $this->accountsReceivableService = app(AccountsReceivableService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
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

        app(\App\Services\StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 100,
            postingDatetime: now(),
        );
    }

    protected function submittedDelivery(int $qty = 10, float $rate = 10000): \App\Models\Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);

        return $this->deliveryService->submit($delivery);
    }

    public function test_invoice_can_be_created_from_a_submitted_delivery(): void
    {
        $delivery = $this->submittedDelivery(qty: 10, rate: 10000);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => 11000,
        ]);

        $this->assertSame($delivery->id, $invoice->delivery_id);
        $this->assertEquals(100000, (float) $invoice->subtotal);
        $this->assertEquals(111000, (float) $invoice->grand_total);
        $this->assertCount(1, $invoice->items);
    }

    public function test_a_delivery_cannot_be_invoiced_twice(): void
    {
        $delivery = $this->submittedDelivery();

        $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->expectException(BusinessException::class);

        $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_submitting_an_invoice_creates_the_accounts_receivable_record_not_the_delivery(): void
    {
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000);

        $this->assertDatabaseCount('accounts_receivables', 0);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertDatabaseCount('accounts_receivables', 0);

        $invoice = $this->invoiceService->submit($invoice);

        $this->assertDatabaseCount('accounts_receivables', 1);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $this->assertSame($invoice->id, $accountsReceivable->invoice_id);
        $this->assertEquals(100000, (float) $accountsReceivable->amount);
    }

    public function test_an_invoice_cannot_be_cancelled_once_it_has_a_payment_applied(): void
    {
        $delivery = $this->submittedDelivery(qty: 2, rate: 50000);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice = $this->invoiceService->submit($invoice);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $this->accountsReceivableService->settle($accountsReceivable, 50000);
        $invoice->refresh();

        $this->expectException(BusinessException::class);

        $this->invoiceService->cancel($invoice);
    }

    /**
     * CR-001: under the finalized Delivery -> Invoice -> AR -> Payment
     * workflow, a payment can only be allocated to an Invoice-originated
     * receivable. A legacy receivable created directly against a Delivery
     * (pre-dating the Invoice module, invoice_id null) must be rejected —
     * it is only reachable this way in a test, since
     * AccountsReceivableService::createFromInvoice() always sets invoice_id.
     * Sprint 12: this guard now lives in PaymentAllocationService, since
     * allocation (not receiving money) is what links a payment to a
     * specific receivable.
     */
    public function test_payment_cannot_be_allocated_against_a_receivable_without_an_invoice(): void
    {
        $delivery = $this->submittedDelivery(qty: 1, rate: 10000);

        $legacyReceivable = AccountsReceivable::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_id' => null,
            'sales_order_id' => $delivery->sales_order_id,
            'delivery_id' => $delivery->id,
            'reference_number' => $delivery->document_number,
            'amount' => 10000,
            'paid_amount' => 0,
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => AccountsReceivableStatus::UNPAID,
        ]);

        $receiptEntry = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::CASH,
            'total_amount' => 5000,
            'allocated_amount' => 0,
        ])->submit();

        $this->expectException(BusinessException::class);

        app(PaymentAllocationService::class)->allocateBatch($receiptEntry, [
            ['accounts_receivable_id' => $legacyReceivable->id, 'amount' => 5000],
        ]);
    }
}
