<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ReceiptEntry;
use App\Models\Permission;
use App\Models\TermsOfPayment;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountsReceivableService;
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
 * Kartu Piutang's "STATEMENT OF ACCOUNT" print — replicates the legacy report's debit-row-grouped
 * table (see AccountsReceivableStatementPrintPage.tsx / rptCustomerStatement.pdf): one row per
 * debit document (Invoice), with its linked payments nested in the row's CREDIT side regardless of
 * the payment's own date, and the running balance walked once per debit row.
 */
class AccountsReceivableStatementTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected DeliveryService $deliveryService;

    protected InvoiceService $invoiceService;

    protected ReceiptEntryService $receiptEntryService;

    protected PaymentAllocationService $paymentAllocationService;

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
        $this->accountsReceivableService = app(AccountsReceivableService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);

        $top = TermsOfPayment::query()->create(['code' => 'NET30', 'name' => 'Net 30', 'days' => 30]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme', 'phone' => '0812345', 'terms_of_payment_id' => $top->id]);

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

    protected function submittedInvoice(int $qty, float $rate, string $invoiceDate, string $dueDate): Invoice
    {
        $this->actingAsCreditOverride();

        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => $invoiceDate,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
            'override_credit_block' => true,
            'override_reason' => 'Test fixture: intentional back-dated invoice for Kartu Piutang statement coverage.',
        ]);
        $this->approveDocument($salesOrder);

        $this->actingAsCreditOverride();
        $this->salesOrderService->approve($salesOrder, true, 'Test fixture: intentional back-dated invoice for Kartu Piutang statement coverage.');

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

    protected function submittedPayment(float $amount, string $receiptDate, ?string $reference = null): ReceiptEntry
    {
        $payment = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => $receiptDate,
            'cash_account_id' => $this->accountId('1100'),
            'total_amount' => $amount,
            'reference_number' => $reference,
        ]);

        return $this->receiptEntryService->submit($payment);
    }

    /**
     * Mirrors the reference PDF's row 4 exactly: one invoice, two payments allocated against it on
     * dates AFTER a later, unrelated second invoice — the statement must still nest both payments
     * under their own invoice's row and walk the running balance in debit-row order, not real
     * chronological order.
     */
    public function test_two_payments_on_one_invoice_nest_under_its_row_regardless_of_payment_date(): void
    {
        $invoiceOne = $this->submittedInvoice(qty: 6, rate: 20000, invoiceDate: '2026-06-23', dueDate: '2026-07-23'); // 120000
        $arOne = $invoiceOne->accountsReceivable()->firstOrFail();

        $invoiceTwo = $this->submittedInvoice(qty: 4, rate: 20000, invoiceDate: '2026-07-31', dueDate: '2026-08-31'); // 80000

        $paymentOne = $this->submittedPayment(20000, '2026-09-29', 'GIRO-1');
        $this->paymentAllocationService->allocateBatch($paymentOne, [
            ['accounts_receivable_id' => $arOne->id, 'amount' => 20000],
        ]);
        $paymentTwo = $this->submittedPayment(20000, '2026-10-13', 'GIRO-2');
        $this->paymentAllocationService->allocateBatch($paymentTwo, [
            ['accounts_receivable_id' => $arOne->id, 'amount' => 20000],
        ]);

        $statement = $this->accountsReceivableService->statement(['customer_id' => $this->customer->id]);

        $this->assertCount(2, $statement['rows']);

        $rowOne = $statement['rows'][0];
        $this->assertEquals($invoiceOne->document_number, $rowOne['document_number']);
        $this->assertEquals(120000, $rowOne['debit_amount']);
        $this->assertCount(2, $rowOne['credits']);
        $this->assertEquals('GIRO-1', $rowOne['credits'][0]['cheque_number']);
        $this->assertEquals(20000, $rowOne['credits'][0]['amount']);
        $this->assertEquals('GIRO-2', $rowOne['credits'][1]['cheque_number']);
        // Balance after row 1: 120000 debit - 40000 credit = 80000 (walked in debit-row order,
        // even though both payments postdate invoiceTwo chronologically).
        $this->assertEquals(80000, $rowOne['balance']);

        $rowTwo = $statement['rows'][1];
        $this->assertEquals($invoiceTwo->document_number, $rowTwo['document_number']);
        $this->assertEquals(80000, $rowTwo['debit_amount']);
        $this->assertCount(0, $rowTwo['credits']);
        $this->assertEquals(160000, $rowTwo['balance']);

        $this->assertEquals(160000, $statement['total_balance']);
    }

    public function test_header_carries_account_no_term_days_and_customer_phone(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->subDays(5)->toDateString(), dueDate: now()->addDays(25)->toDateString());

        $statement = $this->accountsReceivableService->statement(['customer_id' => $this->customer->id]);

        $this->assertEquals('C001', $statement['header']['account_no']);
        $this->assertEquals(30, $statement['header']['terms_of_payment_days']);
        $this->assertEquals('0812345', $statement['header']['customer_phone']);
    }

    /**
     * Only overdue AR counts toward the 6 buckets + the 180+ figure; a not-yet-due invoice
     * contributes 0 to every bucket, so Total Overdue Amount < Total Balance in that case.
     */
    public function test_aging_buckets_split_30_60_90_120_150_180_and_180_plus_separately(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->subDays(200)->toDateString(), dueDate: now()->subDays(185)->toDateString()); // 100000, 185 days overdue -> 180+
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->subDays(50)->toDateString(), dueDate: now()->subDays(35)->toDateString()); // 100000, 35 days overdue -> 60 bucket
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->toDateString(), dueDate: now()->addDays(30)->toDateString()); // 100000, not due yet -> no bucket

        $statement = $this->accountsReceivableService->statement(['customer_id' => $this->customer->id]);

        $this->assertEquals(0, $statement['aging']['bucket_30']);
        $this->assertEquals(100000, $statement['aging']['bucket_60']);
        $this->assertEquals(0, $statement['aging']['bucket_90']);
        $this->assertEquals(0, $statement['aging']['bucket_120']);
        $this->assertEquals(0, $statement['aging']['bucket_150']);
        $this->assertEquals(0, $statement['aging']['bucket_180']);
        $this->assertEquals(100000, $statement['overdue_180_plus']);
        $this->assertEquals(200000, $statement['total_overdue']);
        $this->assertEquals(300000, $statement['total_balance']);
    }

    /**
     * Scenario (c) — a payment received but never allocated to any invoice must appear as its own
     * credit-only row (blank debit side) in the statement too, same gap as the on-screen ledger.
     */
    public function test_unallocated_payment_appears_as_its_own_credit_only_row(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->subDays(10)->toDateString(), dueDate: now()->addDays(20)->toDateString()); // 100000

        $payment = $this->submittedPayment(30000, now()->toDateString(), 'GIRO-UNAPPLIED'); // received, never allocated

        $statement = $this->accountsReceivableService->statement(['customer_id' => $this->customer->id]);

        $this->assertCount(2, $statement['rows']);
        $unappliedRow = $statement['rows'][1];
        $this->assertNull($unappliedRow['document_number']);
        $this->assertEquals(0.0, $unappliedRow['debit_amount']);
        $this->assertCount(1, $unappliedRow['credits']);
        $this->assertEquals($payment->document_number, $unappliedRow['credits'][0]['document_number']);
        $this->assertEquals('GIRO-UNAPPLIED', $unappliedRow['credits'][0]['cheque_number']);
        $this->assertEquals(30000, $unappliedRow['credits'][0]['amount']);
        $this->assertEquals(70000, $unappliedRow['balance']);
        $this->assertEquals(70000, $statement['total_balance']);
    }

    /**
     * CHQ. No must come from ReceiptEntry.reference_number, not giro_number — and a blank
     * reference (valid, common case) must render blank, not fall back to anything else.
     */
    public function test_credit_cheque_number_is_blank_when_receipt_has_no_reference(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->subDays(10)->toDateString(), dueDate: now()->addDays(20)->toDateString());
        $ar = $invoice->accountsReceivable()->firstOrFail();

        $payment = $this->submittedPayment(50000, now()->toDateString());
        $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $ar->id, 'amount' => 50000],
        ]);

        $statement = $this->accountsReceivableService->statement(['customer_id' => $this->customer->id]);

        $this->assertNull($statement['rows'][0]['credits'][0]['cheque_number']);
    }

    public function test_statement_print_endpoint_returns_the_grouped_shape(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: now()->subDays(5)->toDateString(), dueDate: now()->addDays(25)->toDateString());

        Permission::query()->firstOrCreate(['name' => 'reports.ar_detail.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.ar_detail.view');
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/accounts-receivables/ledger/statement-print?customer_id={$this->customer->id}");

        $response->assertOk();
        $response->assertJsonPath('data.header.account_no', 'C001');
        $response->assertJsonPath('data.header.terms_of_payment_days', 30);
        $response->assertJsonCount(1, 'data.rows');
        $response->assertJsonPath('data.rows.0.document_number', $invoice->document_number);
        $response->assertJsonPath('data.rows.0.no', 1);
        $response->assertJsonPath('data.total_balance', 100000);
        $response->assertJsonStructure(['data' => ['aging' => ['bucket_30', 'bucket_60', 'bucket_90', 'bucket_120', 'bucket_150', 'bucket_180']]]);
    }
}
