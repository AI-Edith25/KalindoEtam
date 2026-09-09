<?php

namespace Tests\Feature;

use App\Enums\CreditNoteReason;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\ReceiptEntry;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountsReceivableService;
use App\Services\CreditNoteService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\ReceiptEntryService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kartu Piutang — a single-customer running ledger (opening balance -> dated Debit/Kredit
 * postings -> closing balance) on Reports > AR Detail's 3rd view mode. Built entirely through the
 * real domain services (SalesOrder -> Delivery -> Invoice -> ReceiptEntry/PaymentAllocation ->
 * Credit Note), not the hand-inserted-row shortcut AccountsReceivableExportTest.php uses — this
 * ticket explicitly asked for real-service-built test data.
 */
class AccountsReceivableLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected DeliveryService $deliveryService;

    protected InvoiceService $invoiceService;

    protected ReceiptEntryService $receiptEntryService;

    protected PaymentAllocationService $paymentAllocationService;

    protected CreditNoteService $creditNoteService;

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
        $this->receiptEntryService = app(ReceiptEntryService::class);
        $this->paymentAllocationService = app(PaymentAllocationService::class);
        $this->creditNoteService = app(CreditNoteService::class);
        $this->accountsReceivableService = app(AccountsReceivableService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);

        $this->seedStock($this->item->id, $this->warehouse->id, 1000);
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    /**
     * Every invoice for this customer goes through the real SalesOrder -> Delivery -> Invoice
     * chain. actingAsCreditOverride() is called unconditionally, same defensive posture as
     * AccountsReceivableDetailReportTest::submittedInvoice() — a 2nd+ back-dated invoice for the
     * same customer trips the real Customer Credit block by design; bypassing it here is test
     * fixture setup, not something this suite is meant to verify.
     */
    protected function submittedInvoice(int $qty, float $rate, string $invoiceDate, string $dueDate): Invoice
    {
        $this->actingAsCreditOverride();

        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => $invoiceDate,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
            'override_credit_block' => true,
            'override_reason' => 'Test fixture: intentional back-dated invoice for Kartu Piutang ledger coverage.',
        ]);
        $this->approveDocument($salesOrder);

        $this->actingAsCreditOverride();
        $this->salesOrderService->approve($salesOrder, true, 'Test fixture: intentional back-dated invoice for Kartu Piutang ledger coverage.');

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => $invoiceDate,
            'due_date' => $dueDate,
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
        ]);

        return $this->invoiceService->submit($invoice);
    }

    protected function submittedPayment(float $amount): ReceiptEntry
    {
        $payment = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'total_amount' => $amount,
        ]);

        return $this->receiptEntryService->submit($payment);
    }

    public function test_one_invoice_and_one_partial_payment_closes_at_invoice_minus_paid(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->subDays(10)->toDateString(), dueDate: now()->addDays(20)->toDateString());
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();

        $payment = $this->submittedPayment(40000);
        $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 40000],
        ]);

        $ledger = $this->accountsReceivableService->ledgerFull(['customer_id' => $this->customer->id]);

        $this->assertEquals(0.0, $ledger['opening_balance']);
        $this->assertCount(2, $ledger['rows']);
        $this->assertEquals(100000, $ledger['rows'][0]['debit']); // Invoice
        $this->assertEquals(40000, $ledger['rows'][1]['credit']); // Receipt
        $this->assertEquals(60000, $ledger['closing_balance']);
        $this->assertEquals(60000, $ledger['rows'][1]['running_balance']);
    }

    public function test_period_starting_after_an_earlier_invoice_computes_a_nonzero_opening_balance(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: '2026-01-05', dueDate: '2026-02-05'); // 100000, before the period
        $this->submittedInvoice(qty: 5, rate: 10000, invoiceDate: '2026-03-05', dueDate: '2026-04-05'); // 50000, inside the period

        $ledger = $this->accountsReceivableService->ledgerFull([
            'customer_id' => $this->customer->id,
            'invoice_date_from' => '2026-02-01',
        ]);

        $this->assertEquals(100000, $ledger['opening_balance']);
        $this->assertCount(1, $ledger['rows']); // only the invoice inside the period
        $this->assertEquals(50000, $ledger['rows'][0]['debit']);
        $this->assertEquals(150000, $ledger['closing_balance']);
    }

    public function test_closing_balance_matches_outstanding_total_from_the_aging_list(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000, invoiceDate: now()->subDays(15)->toDateString(), dueDate: now()->addDays(15)->toDateString()); // 200000
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();

        $payment = $this->submittedPayment(30000);
        $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 30000],
        ]);

        $invoiceItem = $invoice->items->first();
        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000, 'restock' => false]],
        ]);
        $this->creditNoteService->submit($creditNote);

        $ledger = $this->accountsReceivableService->ledgerFull(['customer_id' => $this->customer->id]);
        $outstandingTotal = $this->accountsReceivableService->outstandingTotal(['customer_id' => $this->customer->id]);

        $this->assertEquals(130000, $outstandingTotal); // 200000 - 30000 - 40000, Aging List's own ground truth
        $this->assertEquals($outstandingTotal, $ledger['closing_balance']);
    }

    public function test_credit_note_appears_in_kredit_column_and_reduces_running_balance(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000, invoiceDate: now()->subDays(5)->toDateString(), dueDate: now()->addDays(25)->toDateString()); // 200000
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 3, 'amount' => 60000, 'restock' => false]],
        ]);
        $creditNote = $this->creditNoteService->submit($creditNote);

        $ledger = $this->accountsReceivableService->ledgerFull(['customer_id' => $this->customer->id]);

        $this->assertCount(2, $ledger['rows']);
        $creditNoteRow = $ledger['rows'][1];
        $this->assertEquals('Credit Note', $creditNoteRow['document_type']);
        $this->assertEquals($creditNote->document_number, $creditNoteRow['document_number']);
        $this->assertEquals(60000, $creditNoteRow['credit']);
        $this->assertEquals(0.0, $creditNoteRow['debit']);
        $this->assertEquals(140000, $creditNoteRow['running_balance']);
        $this->assertEquals(140000, $ledger['closing_balance']);
    }

    public function test_a_draft_invoice_never_created_an_ar_row_and_produces_no_ledger_rows(): void
    {
        $this->actingAsCreditOverride();
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);
        $this->deliveryService->complete($delivery);

        // Created but never submit()ted — stays Draft, no AccountsReceivable row is ever created for it.
        $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $ledger = $this->accountsReceivableService->ledgerFull(['customer_id' => $this->customer->id]);

        $this->assertCount(0, $ledger['rows']);
        $this->assertEquals(0.0, $ledger['closing_balance']);
    }

    public function test_ledger_endpoint_returns_paginated_shape_with_header_and_aging(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->subDays(5)->toDateString(), dueDate: now()->addDays(25)->toDateString());

        Permission::query()->firstOrCreate(['name' => 'reports.ar_detail.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.ar_detail.view');
        // approveDocument()/actingAsCreditOverride() inside submittedInvoice() above swap the
        // acting user internally — re-act as the intended viewer right before this HTTP call.
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/accounts-receivables/ledger?customer_id={$this->customer->id}");

        $response->assertOk();
        $response->assertJsonPath('data.header.customer_code', 'C001');
        $response->assertJsonPath('data.opening_balance', 0);
        $response->assertJsonCount(1, 'data.rows');
        $response->assertJsonPath('data.rows.0.document_number', $invoice->document_number);
        $response->assertJsonPath('data.closing_balance', 100000);
        $response->assertJsonStructure(['data' => ['aging' => ['not_due', 'due_1_30', 'due_31_60', 'due_61_90', 'due_over_90']]]);
        $response->assertJsonPath('meta.total', 1);
    }
}
