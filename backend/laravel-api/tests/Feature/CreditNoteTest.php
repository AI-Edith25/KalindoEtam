<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\CreditNoteService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected CreditNoteService $creditNoteService;
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
        $this->creditNoteService = app(CreditNoteService::class);

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

    protected function submittedInvoice(int $qty = 10, float $rate = 20000, float $taxAmount = 0): Invoice
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
        $this->deliveryService->submit($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => $taxAmount,
        ]);

        return $this->invoiceService->submit($invoice);
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_partial_credit_note_reduces_receivable_and_posts_balanced_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000); // grand_total 200000
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [
                ['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 3, 'amount' => 60000, 'restock' => true],
            ],
        ]);

        $this->assertEquals(DocumentStatus::DRAFT, $creditNote->status);
        $this->assertEquals(60000, (float) $creditNote->subtotal);
        $this->assertEquals(60000, (float) $creditNote->total_amount);

        $creditNote = $this->creditNoteService->submit($creditNote);

        $this->assertEquals(DocumentStatus::SUBMITTED, $creditNote->status);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(140000, (float) $accountsReceivable->amount); // 200000 - 60000
        $this->assertEquals(60000, (float) $accountsReceivable->credited_amount);
        $this->assertEquals(AccountsReceivableStatus::UNPAID, $accountsReceivable->status);

        $journalEntry = JournalEntry::query()->where('reference_type', 'credit_note')->where('reference_id', $creditNote->id)->firstOrFail();
        $this->assertEquals(DocumentStatus::SUBMITTED, $journalEntry->status);
        $this->assertEquals(60000, (float) $journalEntry->total_debit);
        $this->assertEquals(60000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(60000, (float) $lines->firstWhere('chartOfAccount.code', '4050')->debit);
        $this->assertEquals(60000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->credit);

        // No inventory movement is posted for the Credit Note itself yet — Sprint 13B decision
        // (Pending Inventory Return Module). Only the setUp() stock-in and the Delivery's own
        // stock-out exist; a third row here would mean the Credit Note wrongly touched stock.
        $this->assertDatabaseCount('stock_ledgers', 2);
    }

    public function test_full_credit_must_cover_the_entire_remaining_balance(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // grand_total 100000
        $invoiceItem = $invoice->items->first();

        try {
            $this->creditNoteService->create([
                'invoice_id' => $invoice->id,
                'credit_note_date' => now()->toDateString(),
                'reason' => CreditNoteReason::FULL_CREDIT->value,
                'items' => [
                    ['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000],
                ],
            ]);
            $this->fail('Expected a Full Credit that does not cover the entire balance to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('credit_notes', 0);

        // The exact remaining balance succeeds.
        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::FULL_CREDIT->value,
            'items' => [
                ['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 5, 'amount' => 100000],
            ],
        ]);

        $this->assertEquals(100000, (float) $creditNote->total_amount);
    }

    public function test_tax_adjustment_credit_note_has_no_lines_and_posts_only_the_tax_leg(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 10000); // grand_total 110000

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::TAX_ADJUSTMENT->value,
            'tax_amount' => 10000,
        ]);

        $this->assertEquals(0, (float) $creditNote->subtotal);
        $this->assertEquals(10000, (float) $creditNote->total_amount);

        $creditNote = $this->creditNoteService->submit($creditNote);

        $journalEntry = JournalEntry::query()->where('reference_type', 'credit_note')->where('reference_id', $creditNote->id)->firstOrFail();
        $lines = $journalEntry->lines()->with('chartOfAccount')->get();

        $this->assertCount(2, $lines);
        $this->assertEquals(10000, (float) $lines->firstWhere('chartOfAccount.code', '2100')->debit);
        $this->assertEquals(10000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->credit);
    }

    public function test_multiple_credit_notes_cannot_jointly_exceed_the_invoice_balance(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000); // grand_total 200000
        $invoiceItem = $invoice->items->first();

        $first = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 6, 'amount' => 120000]],
        ]);
        $this->creditNoteService->submit($first);

        try {
            $this->creditNoteService->create([
                'invoice_id' => $invoice->id,
                'credit_note_date' => now()->toDateString(),
                'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
                'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 5, 'amount' => 100000]],
            ]);
            $this->fail('Expected a second Credit Note exceeding the remaining balance to throw.');
        } catch (BusinessException) {
        }

        // Exactly the remaining balance (80000) and remaining qty (4) succeeds.
        $second = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 4, 'amount' => 80000]],
        ]);
        $this->creditNoteService->submit($second);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(0, (float) $accountsReceivable->amount);
        $this->assertEquals(200000, (float) $accountsReceivable->credited_amount);
    }

    public function test_credit_note_against_an_already_fully_paid_invoice_can_result_in_overpaid_state(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // grand_total 100000
        $invoiceItem = $invoice->items->first();
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();

        app(\App\Services\AccountsReceivableService::class)->settle($accountsReceivable, 100000);
        $this->assertEquals(AccountsReceivableStatus::PAID, $accountsReceivable->fresh()->status);

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::RETURNED_GOODS->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000, 'restock' => true]],
        ]);
        $this->creditNoteService->submit($creditNote);

        $accountsReceivable->refresh();
        $this->assertEquals(60000, (float) $accountsReceivable->amount); // 100000 - 40000
        $this->assertEquals(100000, (float) $accountsReceivable->paid_amount); // untouched
        $this->assertEquals(AccountsReceivableStatus::PAID, $accountsReceivable->status); // still >= amount
    }

    public function test_reverse_restores_receivable_and_posts_swapped_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // grand_total 100000
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000]],
        ]);
        $creditNote = $this->creditNoteService->submit($creditNote);

        $originalJournal = JournalEntry::query()->where('reference_type', 'credit_note')->where('reference_id', $creditNote->id)->firstOrFail();

        $reversed = $this->creditNoteService->reverse($creditNote);

        $this->assertTrue($reversed->is_reversed);
        $this->assertNotNull($reversed->reversed_at);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(100000, (float) $accountsReceivable->amount);
        $this->assertEquals(0, (float) $accountsReceivable->credited_amount);

        $originalJournal->refresh();
        $this->assertEquals(DocumentStatus::SUBMITTED, $originalJournal->status);
        $this->assertNotNull($originalJournal->reversed_by_id);

        $reversalJournal = JournalEntry::query()->findOrFail($originalJournal->reversed_by_id);
        $reversalLines = $reversalJournal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(40000, (float) $reversalLines->firstWhere('chartOfAccount.code', '4050')->credit);
        $this->assertEquals(40000, (float) $reversalLines->firstWhere('chartOfAccount.code', '1200')->debit);

        // Reversal frees the credited qty/amount for a new Credit Note.
        $again = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000]],
        ]);
        $this->assertEquals(40000, (float) $again->total_amount);
    }

    public function test_reverse_twice_throws(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 1, 'amount' => 20000]],
        ]);
        $creditNote = $this->creditNoteService->submit($creditNote);
        $this->creditNoteService->reverse($creditNote);

        try {
            $this->creditNoteService->reverse($creditNote->fresh());
            $this->fail('Expected reversing an already-reversed Credit Note to throw.');
        } catch (BusinessException) {
        }

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(100000, (float) $accountsReceivable->amount);
    }

    public function test_deleting_a_draft_credit_note_removes_it(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 1, 'amount' => 20000]],
        ]);

        $this->creditNoteService->delete($creditNote);

        $this->assertSoftDeleted('credit_notes', ['id' => $creditNote->id]);
    }

    public function test_a_submitted_credit_note_cannot_be_updated_deleted_or_resubmitted(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 1, 'amount' => 20000]],
        ]);
        $creditNote = $this->creditNoteService->submit($creditNote);

        try {
            $this->creditNoteService->update($creditNote, ['remarks' => 'changed']);
            $this->fail('Expected updating a submitted Credit Note to throw.');
        } catch (BusinessException) {
        }

        try {
            $this->creditNoteService->delete($creditNote);
            $this->fail('Expected deleting a submitted Credit Note to throw.');
        } catch (BusinessException) {
        }

        try {
            $this->creditNoteService->submit($creditNote->fresh());
            $this->fail('Expected re-submitting a submitted Credit Note to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseHas('credit_notes', ['id' => $creditNote->id, 'deleted_at' => null]);
    }

    /** CreditNote::cancel() must throw — reverse() is the only correction path for a submitted note. */
    public function test_credit_note_cancel_is_blocked(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 1, 'amount' => 20000]],
        ]);
        $creditNote = $this->creditNoteService->submit($creditNote);

        $this->expectException(BusinessException::class);
        $creditNote->cancel();
    }

    public function test_credit_note_submission_rolls_back_completely_if_journal_posting_fails(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000]],
        ]);

        ChartOfAccount::query()->where('code', '4050')->update(['is_active' => false]);

        try {
            $this->creditNoteService->submit($creditNote);
            $this->fail('Expected submit() to throw when the required chart of account is inactive.');
        } catch (BusinessException) {
        }

        $this->assertEquals(DocumentStatus::DRAFT, $creditNote->fresh()->status);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(100000, (float) $accountsReceivable->amount);
        $this->assertEquals(0, (float) $accountsReceivable->credited_amount);
        // Only the Invoice's own journal entry should exist — none for the failed Credit Note.
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_second_credit_note_fails_once_line_quantity_is_exhausted_by_a_concurrent_submit(): void
    {
        $invoice = $this->submittedInvoice(qty: 4, rate: 20000); // grand_total 80000, qty 4
        $invoiceItem = $invoice->items->first();

        $first = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::RETURNED_GOODS->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 4, 'amount' => 80000, 'restock' => true]],
        ]);
        $this->creditNoteService->submit($first);

        $second = CreditNote::query()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::RETURNED_GOODS->value,
            'subtotal' => 0,
            'total_amount' => 0,
        ]);

        try {
            $this->creditNoteService->update($second, [
                'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 1, 'amount' => 20000]],
            ]);
            $this->fail('Expected the second Credit Note to throw once the line is already fully credited.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('credit_note_items', 1); // only the first Credit Note's line — the second never persisted one
    }

    /**
     * Two sequential (simulating racing) submit() calls against an Invoice
     * with only enough remaining balance for one — the row lock on the
     * AccountsReceivable (see CreditNoteService::submit()) means the
     * second correctly re-validates against the first's already-committed
     * writeDown() rather than a stale balance, same technique as
     * PaymentAllocationTest's own concurrency test.
     */
    public function test_two_concurrent_submits_against_the_same_invoice_do_not_jointly_over_credit(): void
    {
        $invoice = $this->submittedInvoice(qty: 2, rate: 20000); // grand_total 40000
        $invoiceItem = $invoice->items->first();

        $first = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000]],
        ]);
        $second = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000]],
        ]);

        $this->creditNoteService->submit($first);

        try {
            $this->creditNoteService->submit($second);
            $this->fail('Expected the second submit() to throw after the lock is released and the balance is re-checked.');
        } catch (BusinessException) {
        }

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(0, (float) $accountsReceivable->amount);
        $this->assertEquals(40000, (float) $accountsReceivable->credited_amount);
        $this->assertEquals(DocumentStatus::DRAFT, $second->fresh()->status);
    }

    /**
     * End-to-end ledger-balance proof, same shape as PaymentAllocationTest::
     * test_receiving_then_allocating_a_payment_nets_the_suspense_account_to_zero() —
     * after a full Invoice -> Credit Note -> Reverse chain, every posted
     * Journal Entry's debits still equal its credits, and reversing the
     * Credit Note nets the contra-revenue account back to zero.
     */
    public function test_accounting_stays_balanced_across_a_full_invoice_credit_note_reverse_chain(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 5000); // grand_total 105000
        $invoiceItem = $invoice->items->first();

        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PARTIAL_CREDIT->value,
            'tax_amount' => 2500,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 2, 'amount' => 40000]],
        ]);
        $creditNote = $this->creditNoteService->submit($creditNote);

        foreach (JournalEntry::all() as $journalEntry) {
            $this->assertEquals(
                round((float) $journalEntry->total_debit, 2),
                round((float) $journalEntry->total_credit, 2),
                "Journal Entry {$journalEntry->document_number} is not balanced.",
            );
        }

        $this->creditNoteService->reverse($creditNote);

        foreach (JournalEntry::all() as $journalEntry) {
            $this->assertEquals(
                round((float) $journalEntry->total_debit, 2),
                round((float) $journalEntry->total_credit, 2),
                "Journal Entry {$journalEntry->document_number} is not balanced after reversal.",
            );
        }

        // The contra-revenue account nets to zero once the Credit Note posting it is reversed.
        $contraRevenueAccountId = $this->accountId('4050');
        $netContraRevenue = \App\Models\JournalEntryLine::query()->where('chart_of_account_id', $contraRevenueAccountId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertEquals(0, (float) $netContraRevenue);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(105000, (float) $accountsReceivable->amount);
    }
}
