<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Enums\CreditNoteReason;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\AccountsReceivable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Models\ReceiptEntry;
use App\Services\AccountsReceivableService;
use App\Services\CreditNoteService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\SalesOrderService;
use Illuminate\Database\QueryException;
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
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => 11000,
        ]);

        $this->assertSame($delivery->id, $invoice->delivery_id);
        $this->assertEquals(100000, (float) $invoice->subtotal);
        $this->assertEquals(111000, (float) $invoice->grand_total);
        $this->assertCount(1, $invoice->items);
    }

    public function test_invoice_supports_a_fixed_amount_discount(): void
    {
        $delivery = $this->submittedDelivery(qty: 10, rate: 10000);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'discount_type' => 'amount',
            'discount_amount' => 15000,
        ]);

        $this->assertEquals(100000, (float) $invoice->subtotal);
        $this->assertEquals(15000, (float) $invoice->discount_amount);
        $this->assertSame('amount', $invoice->discount_type->value);
        $this->assertNull($invoice->discount_percentage);
        $this->assertEquals(85000, (float) $invoice->grand_total);
    }

    public function test_invoice_supports_a_percentage_discount_derived_from_subtotal(): void
    {
        $delivery = $this->submittedDelivery(qty: 10, rate: 10000);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'discount_type' => 'percentage',
            'discount_percentage' => 10,
        ]);

        $this->assertEquals(100000, (float) $invoice->subtotal);
        $this->assertEquals(10000, (float) $invoice->discount_amount);
        $this->assertSame('percentage', $invoice->discount_type->value);
        $this->assertEquals(10, (float) $invoice->discount_percentage);
        $this->assertEquals(90000, (float) $invoice->grand_total);
    }

    public function test_a_fixed_discount_amount_cannot_exceed_the_subtotal(): void
    {
        $delivery = $this->submittedDelivery(qty: 1, rate: 10000);

        $this->expectException(BusinessException::class);

        $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'discount_type' => 'amount',
            'discount_amount' => 20000,
        ]);
    }

    public function test_an_invoice_without_a_discount_type_defaults_to_amount_mode(): void
    {
        $delivery = $this->submittedDelivery(qty: 1, rate: 10000);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'discount_amount' => 1000,
        ]);

        $this->assertSame('amount', $invoice->discount_type->value);
        $this->assertEquals(1000, (float) $invoice->discount_amount);
        $this->assertNull($invoice->discount_percentage);
    }

    public function test_updating_an_invoice_can_switch_it_to_a_percentage_discount(): void
    {
        $delivery = $this->submittedDelivery(qty: 10, rate: 10000);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $invoice = $this->invoiceService->update($invoice, [
            'discount_type' => 'percentage',
            'discount_percentage' => 25,
        ]);

        $this->assertSame('percentage', $invoice->discount_type->value);
        $this->assertEquals(25, (float) $invoice->discount_percentage);
        $this->assertEquals(25000, (float) $invoice->discount_amount);
        $this->assertEquals(75000, (float) $invoice->grand_total);
    }

    public function test_a_delivery_cannot_be_invoiced_twice(): void
    {
        $delivery = $this->submittedDelivery();

        $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->expectException(BusinessException::class);

        $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_invoice_can_be_created_from_multiple_deliveries_of_the_same_customer(): void
    {
        $deliveryA = $this->submittedDelivery(qty: 10, rate: 10000);
        $deliveryB = $this->submittedDelivery(qty: 5, rate: 20000);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$deliveryA->id, $deliveryB->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertEquals(200000, (float) $invoice->subtotal);
        $this->assertCount(2, $invoice->items);
        $this->assertCount(2, $invoice->deliveries);
        $this->assertEqualsCanonicalizing([$deliveryA->id, $deliveryB->id], $invoice->deliveries->pluck('id')->all());
    }

    public function test_merging_deliveries_from_different_customers_is_rejected(): void
    {
        $deliveryA = $this->submittedDelivery();

        $otherCustomer = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Wayne Inc']);
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $otherCustomer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);
        $deliveryB = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);
        $deliveryB = $this->deliveryService->submit($deliveryB);

        $this->expectException(BusinessException::class);

        $this->invoiceService->create([
            'delivery_ids' => [$deliveryA->id, $deliveryB->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    /**
     * The critical regression this redesign exists to prevent: eligibility
     * must be checked against the full invoice_deliveries pivot, not just
     * the anchor invoices.delivery_id column — otherwise the Delivery that
     * *isn't* the anchor of a merged Invoice would still look "not yet
     * invoiced" and could be invoiced again elsewhere.
     */
    public function test_a_non_anchor_delivery_from_a_merged_invoice_cannot_be_invoiced_again(): void
    {
        $deliveryA = $this->submittedDelivery(qty: 10, rate: 10000);
        $deliveryB = $this->submittedDelivery(qty: 5, rate: 20000);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$deliveryA->id, $deliveryB->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $nonAnchorId = $invoice->delivery_id === $deliveryA->id ? $deliveryB->id : $deliveryA->id;

        $this->expectException(BusinessException::class);

        $this->invoiceService->create([
            'delivery_ids' => [$nonAnchorId],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_a_merged_invoice_tracks_every_source_sales_order(): void
    {
        $deliveryA = $this->submittedDelivery(qty: 10, rate: 10000);
        $deliveryB = $this->submittedDelivery(qty: 5, rate: 20000);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$deliveryA->id, $deliveryB->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertCount(2, $invoice->salesOrders);
        $this->assertEqualsCanonicalizing(
            [$deliveryA->sales_order_id, $deliveryB->sales_order_id],
            $invoice->salesOrders->pluck('id')->all()
        );
        $this->assertContains($invoice->sales_order_id, [$deliveryA->sales_order_id, $deliveryB->sales_order_id]);
    }

    public function test_merging_two_deliveries_from_the_same_sales_order_does_not_duplicate_the_sales_order_link(): void
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 10000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);
        $soItem = $salesOrder->items->first();

        $deliveryA = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 5]],
        ]);
        $deliveryA = $this->deliveryService->submit($deliveryA);

        $deliveryB = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 5]],
        ]);
        $deliveryB = $this->deliveryService->submit($deliveryB);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$deliveryA->id, $deliveryB->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertCount(1, $invoice->salesOrders);
        $this->assertSame($salesOrder->id, $invoice->salesOrders->first()->id);
    }

    public function test_a_transportation_invoice_can_be_created_with_a_customer_and_manual_items_without_any_delivery(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'items' => [
                ['description' => 'Ongkos Angkut Semen 50kg - Rute A', 'qty' => 3, 'rate' => 25000],
                ['description' => 'Ongkos Angkut Semen 50kg - Rute B', 'qty' => 2, 'rate' => 30000],
            ],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertNull($invoice->delivery_id);
        $this->assertNull($invoice->sales_order_id);
        $this->assertSame($this->customer->id, $invoice->customer_id);
        $this->assertEquals(135000, (float) $invoice->subtotal); // (3*25000) + (2*30000)
        $this->assertCount(2, $invoice->items);

        $line = $invoice->items->first();
        $this->assertNull($line->delivery_item_id);
        $this->assertNull($line->item_id);
        $this->assertSame('Ongkos Angkut Semen 50kg - Rute A', $line->item_name);
    }

    public function test_submitting_a_transportation_invoice_creates_the_accounts_receivable_and_posts_the_journal(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'items' => [
                ['description' => 'Ongkos Angkut Semen', 'qty' => 1, 'rate' => 500000],
            ],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertDatabaseCount('accounts_receivables', 0);

        $invoice = $this->invoiceService->submit($invoice);

        $this->assertDatabaseCount('accounts_receivables', 1);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $this->assertSame($invoice->id, $accountsReceivable->invoice_id);
        $this->assertNull($accountsReceivable->delivery_id);
        $this->assertNull($accountsReceivable->sales_order_id);
        $this->assertEquals(500000, (float) $accountsReceivable->amount);

        $journalEntry = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->firstOrFail();
        $this->assertEquals(500000, (float) $journalEntry->total_debit);
        $this->assertEquals(500000, (float) $journalEntry->total_credit);
    }

    public function test_a_transportation_invoice_requires_a_customer_and_at_least_one_item(): void
    {
        $this->expectException(QueryException::class);

        $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => null,
            'items' => [],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_store_invoice_request_requires_customer_and_items_for_transportation_but_not_delivery_ids(): void
    {
        $validator = validator([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ], (new StoreInvoiceRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('customer_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('items', $validator->errors()->toArray());
        $this->assertArrayNotHasKey('delivery_ids', $validator->errors()->toArray());
    }

    public function test_store_invoice_request_requires_delivery_ids_for_goods_but_not_customer_or_items(): void
    {
        $validator = validator([
            'invoice_type' => InvoiceType::GOODS->value,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ], (new StoreInvoiceRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('delivery_ids', $validator->errors()->toArray());
        $this->assertArrayNotHasKey('customer_id', $validator->errors()->toArray());
        $this->assertArrayNotHasKey('items', $validator->errors()->toArray());
    }

    public function test_a_credit_note_cannot_be_raised_against_a_transportation_invoice(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'items' => [
                ['description' => 'Ongkos Angkut Semen', 'qty' => 1, 'rate' => 500000],
            ],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice = $this->invoiceService->submit($invoice);

        $this->expectException(BusinessException::class);

        app(CreditNoteService::class)->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::SERVICE_REFUND->value,
            'items' => [],
        ]);
    }

    public function test_submitting_an_invoice_creates_the_accounts_receivable_record_not_the_delivery(): void
    {
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000);

        $this->assertDatabaseCount('accounts_receivables', 0);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
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
            'delivery_ids' => [$delivery->id],
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
            'cash_account_id' => \App\Models\ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
            'total_amount' => 5000,
            'allocated_amount' => 0,
        ])->submit();

        $this->expectException(BusinessException::class);

        app(PaymentAllocationService::class)->allocateBatch($receiptEntry, [
            ['accounts_receivable_id' => $legacyReceivable->id, 'amount' => 5000],
        ]);
    }
}
