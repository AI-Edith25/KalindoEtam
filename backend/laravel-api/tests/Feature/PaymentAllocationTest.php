<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\PaymentAllocation;
use App\Models\ReceiptEntry;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\ReceiptEntryService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected PaymentAllocationService $paymentAllocationService;
    protected ReceiptEntryService $receiptEntryService;
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
        $this->paymentAllocationService = app(PaymentAllocationService::class);
        $this->receiptEntryService = app(ReceiptEntryService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['branch_id' => $branch->id, 'name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
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

    protected function submittedInvoice(int $qty, float $rate): Invoice
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
        $delivery = $this->deliveryService->submit($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->invoiceService->submit($invoice);
    }

    protected function accountId(string $code): string
    {
        return \App\Models\ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    /**
     * A payment that has been received but not yet allocated to anything.
     * Bypasses ReceiptEntryService and drives Documentable directly, since
     * most tests here only care about PaymentAllocationService's own
     * behavior against a payment's status/total_amount/allocated_amount —
     * see test_receiving_then_allocating_a_payment_nets_the_suspense_account_to_zero()
     * for a test that goes through the real ReceiptEntryService instead.
     */
    protected function submittedPayment(float $amount): ReceiptEntry
    {
        $receiptEntry = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::CASH,
            'total_amount' => $amount,
            'allocated_amount' => 0,
        ]);

        return $receiptEntry->submit();
    }

    /**
     * The two-leg suspense-account model end to end, through the real
     * ReceiptEntryService (receive) and PaymentAllocationService (allocate)
     * — not the test's submittedPayment() bypass. Confirms the '1150'
     * Unapplied Customer Payments account nets to zero once a receipt is
     * fully allocated: the receipt's journal debits it, the allocation's
     * journal credits it by the same amount.
     */
    public function test_receiving_then_allocating_a_payment_nets_the_suspense_account_to_zero(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();

        $payment = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::CASH,
            'total_amount' => 100000,
        ]);
        $payment = $this->receiptEntryService->submit($payment);

        $receiptJournal = JournalEntry::query()->where('reference_type', 'receipt_entry')->where('reference_id', $payment->id)->firstOrFail();
        $receiptLines = $receiptJournal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $receiptLines->firstWhere('chartOfAccount.code', '1100')->debit);
        $this->assertEquals(100000, (float) $receiptLines->firstWhere('chartOfAccount.code', '1150')->credit);
        $this->assertEquals(0, $accountsReceivable->fresh()->paid_amount); // not yet allocated

        $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 100000],
        ]);

        $this->assertEquals(100000, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(0, $payment->fresh()->unallocatedAmount());

        $suspenseAccountId = $this->accountId('1150');
        $netSuspense = \App\Models\JournalEntryLine::query()->where('chart_of_account_id', $suspenseAccountId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertEquals(0, (float) $netSuspense);
    }

    public function test_allocate_batch_creates_allocation_settles_ar_and_posts_balanced_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(100000);

        $allocations = $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 100000],
        ]);

        $this->assertCount(1, $allocations);
        $allocation = $allocations->first();
        $this->assertEquals(100000, (float) $allocation->allocated_amount);
        $this->assertFalse($allocation->is_reversed);

        $this->assertEquals(100000, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(AccountsReceivableStatus::PAID, $accountsReceivable->fresh()->status);

        $freshPayment = $payment->fresh();
        $this->assertEquals(100000, (float) $freshPayment->allocated_amount);
        $this->assertEquals(0, $freshPayment->unallocatedAmount());

        $journalEntry = JournalEntry::query()->where('reference_type', 'payment_allocation')->where('reference_id', $allocation->id)->firstOrFail();
        $this->assertEquals(DocumentStatus::SUBMITTED, $journalEntry->status);
        $this->assertEquals(100000, (float) $journalEntry->total_debit);
        $this->assertEquals(100000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '1150')->debit);
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->credit);
    }

    /** Partial allocation: only part of the payment is applied now, the rest stays available for a later allocateBatch() call. */
    public function test_allocate_batch_supports_partial_allocation_leaving_a_balance_for_later(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000); // 200000 outstanding
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(100000);

        $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 60000],
        ]);

        $this->assertEquals(60000, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(AccountsReceivableStatus::PARTIALLY_PAID, $accountsReceivable->fresh()->status);
        $this->assertEquals(60000, (float) $payment->fresh()->allocated_amount);
        $this->assertEquals(40000, $payment->fresh()->unallocatedAmount());

        // The remaining 40000 is still allocatable, against the same or a different receivable.
        $this->paymentAllocationService->allocateBatch($payment->fresh(), [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 40000],
        ]);

        $this->assertEquals(100000, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(100000, (float) $payment->fresh()->allocated_amount);
        $this->assertEquals(0, $payment->fresh()->unallocatedAmount());
        $this->assertCount(2, PaymentAllocation::all());
    }

    public function test_allocate_batch_splits_one_payment_across_multiple_invoices(): void
    {
        $invoice1 = $this->submittedInvoice(qty: 2, rate: 20000);
        $invoice2 = $this->submittedInvoice(qty: 3, rate: 20000);
        $ar1 = $invoice1->accountsReceivable()->firstOrFail();
        $ar2 = $invoice2->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(100000);

        $allocations = $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $ar1->id, 'amount' => 40000],
            ['accounts_receivable_id' => $ar2->id, 'amount' => 60000],
        ]);

        $this->assertCount(2, $allocations);
        $this->assertEquals(40000, (float) $ar1->fresh()->paid_amount);
        $this->assertEquals(60000, (float) $ar2->fresh()->paid_amount);
        $this->assertEquals(100000, (float) $payment->fresh()->allocated_amount);
        $this->assertDatabaseCount('journal_entries', 4); // 2 invoice journals + 2 allocation journals
    }

    public function test_allocate_batch_rejects_amount_exceeding_payment_unallocated_balance(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(50000);

        try {
            $this->paymentAllocationService->allocateBatch($payment, [
                ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 60000],
            ]);
            $this->fail('Expected allocating more than the payment\'s unallocated balance to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertEquals(0, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);
    }

    public function test_allocate_batch_rejects_amount_exceeding_receivable_outstanding(): void
    {
        $invoice = $this->submittedInvoice(qty: 2, rate: 20000); // 40000 outstanding
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(100000);

        try {
            $this->paymentAllocationService->allocateBatch($payment, [
                ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 60000],
            ]);
            $this->fail('Expected allocating more than the receivable\'s outstanding to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);
    }

    public function test_allocate_batch_rejects_duplicate_receivable_in_same_batch(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(200000);

        try {
            $this->paymentAllocationService->allocateBatch($payment, [
                ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 50000],
                ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 50000],
            ]);
            $this->fail('Expected duplicate Accounts Receivable references in one batch to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_allocate_batch_rejects_a_draft_payment(): void
    {
        $invoice = $this->submittedInvoice(qty: 1, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();

        $draftPayment = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::CASH,
            'total_amount' => 20000,
            'allocated_amount' => 0,
        ]);

        try {
            $this->paymentAllocationService->allocateBatch($draftPayment, [
                ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 20000],
            ]);
            $this->fail('Expected allocating an unsubmitted payment to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_reverse_restores_receivable_and_payment_balance_and_posts_swapped_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(100000);

        $allocation = $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 100000],
        ])->first();

        $originalJournal = JournalEntry::query()->where('reference_type', 'payment_allocation')->where('reference_id', $allocation->id)->firstOrFail();

        $reversed = $this->paymentAllocationService->reverse($allocation);

        $this->assertTrue($reversed->is_reversed);
        $this->assertEquals(0, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(AccountsReceivableStatus::UNPAID, $accountsReceivable->fresh()->status);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);

        $originalJournal->refresh();
        $this->assertEquals(DocumentStatus::SUBMITTED, $originalJournal->status);
        $this->assertNotNull($originalJournal->reversed_by_id);

        $reversalJournal = JournalEntry::query()->findOrFail($originalJournal->reversed_by_id);
        $reversalLines = $reversalJournal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $reversalLines->firstWhere('chartOfAccount.code', '1150')->credit);
        $this->assertEquals(100000, (float) $reversalLines->firstWhere('chartOfAccount.code', '1200')->debit);
    }

    public function test_reverse_twice_throws(): void
    {
        $invoice = $this->submittedInvoice(qty: 1, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(20000);

        $allocation = $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 20000],
        ])->first();

        $this->paymentAllocationService->reverse($allocation);

        try {
            $this->paymentAllocationService->reverse($allocation->fresh());
            $this->fail('Expected reversing an already-reversed allocation to throw.');
        } catch (BusinessException) {
        }

        // The failed second reverse must not have double-restored the balances.
        $this->assertEquals(0, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);
    }

    /** Double-submit: replaying the exact same allocation request (e.g. a double-click) must not double-apply it. */
    public function test_double_submitting_the_same_allocation_request_fails_on_the_second_call(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(100000);
        $lines = [['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 100000]];

        $this->paymentAllocationService->allocateBatch($payment, $lines);

        try {
            $this->paymentAllocationService->allocateBatch($payment->fresh(), $lines);
            $this->fail('Expected replaying the same allocation request to throw.');
        } catch (BusinessException) {
        }

        $this->assertCount(1, PaymentAllocation::all());
        $this->assertEquals(100000, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(100000, (float) $payment->fresh()->allocated_amount);
    }

    public function test_allocate_batch_rolls_back_completely_if_journal_posting_fails(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = $this->submittedPayment(100000);

        \App\Models\ChartOfAccount::query()->where('code', '1150')->update(['is_active' => false]);

        try {
            $this->paymentAllocationService->allocateBatch($payment, [
                ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 100000],
            ]);
            $this->fail('Expected allocateBatch() to throw when the required chart of account is inactive.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertEquals(0, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);
        // Only the Invoice's own journal from submittedInvoice() should exist — none for the failed allocation.
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_deleting_a_draft_payment_removes_it(): void
    {
        $draftPayment = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::CASH,
            'total_amount' => 50000,
            'allocated_amount' => 0,
        ]);

        $this->receiptEntryService->delete($draftPayment);

        $this->assertSoftDeleted('receipt_entries', ['id' => $draftPayment->id]);
    }

    /** "Posted" payment (submitted, per this app's Documentable status) must be immutable — same precedent as every other document module. */
    public function test_a_submitted_payment_cannot_be_updated_or_deleted(): void
    {
        $payment = $this->submittedPayment(50000);

        try {
            $this->receiptEntryService->update($payment, ['total_amount' => 99999]);
            $this->fail('Expected updating a submitted payment to throw.');
        } catch (BusinessException) {
        }

        try {
            $this->receiptEntryService->delete($payment);
            $this->fail('Expected deleting a submitted payment to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseHas('receipt_entries', ['id' => $payment->id, 'total_amount' => 50000, 'deleted_at' => null]);
    }

    public function test_second_allocation_fails_once_receivable_outstanding_is_exhausted(): void
    {
        $invoice = $this->submittedInvoice(qty: 2, rate: 20000); // 40000 outstanding
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $paymentOne = $this->submittedPayment(40000);
        $paymentTwo = $this->submittedPayment(40000);

        $this->paymentAllocationService->allocateBatch($paymentOne, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 40000],
        ]);

        try {
            $this->paymentAllocationService->allocateBatch($paymentTwo, [
                ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 40000],
            ]);
            $this->fail('Expected the second allocation against an already-settled receivable to throw.');
        } catch (BusinessException) {
        }

        $this->assertEquals(40000, (float) $accountsReceivable->fresh()->paid_amount);
        $this->assertEquals(0, (float) $paymentTwo->fresh()->allocated_amount);
        $this->assertCount(1, PaymentAllocation::all());
    }
}
