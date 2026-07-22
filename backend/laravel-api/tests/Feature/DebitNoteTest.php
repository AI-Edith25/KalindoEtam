<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Enums\DebitNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\AccountsReceivableService;
use App\Services\CreditNoteService;
use App\Services\DebitNoteService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Enums\CreditNoteReason;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DebitNoteTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected DebitNoteService $debitNoteService;
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
        $this->debitNoteService = app(DebitNoteService::class);
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

    public function test_under_billed_invoice_debit_note_increases_receivable_and_posts_balanced_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000); // grand_total 200000
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::UNDER_BILLED_INVOICE->value,
            'items' => [
                ['invoice_item_id' => $invoiceItem->id, 'qty_adjusted' => 3, 'amount' => 60000],
            ],
        ]);

        $this->assertEquals(DocumentStatus::DRAFT, $debitNote->status);
        $this->assertEquals(60000, (float) $debitNote->subtotal_goods);
        $this->assertEquals(0, (float) $debitNote->subtotal_other);
        $this->assertEquals(60000, (float) $debitNote->total_amount);

        $debitNote = $this->debitNoteService->submit($debitNote);

        $this->assertEquals(DocumentStatus::SUBMITTED, $debitNote->status);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(260000, (float) $accountsReceivable->amount); // 200000 + 60000
        $this->assertEquals(60000, (float) $accountsReceivable->debited_amount);
        $this->assertEquals(AccountsReceivableStatus::UNPAID, $accountsReceivable->status);

        $journalEntry = JournalEntry::query()->where('reference_type', 'debit_note')->where('reference_id', $debitNote->id)->firstOrFail();
        $this->assertEquals(DocumentStatus::SUBMITTED, $journalEntry->status);
        $this->assertEquals(60000, (float) $journalEntry->total_debit);
        $this->assertEquals(60000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(60000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->debit);
        $this->assertEquals(60000, (float) $lines->firstWhere('chartOfAccount.code', '4000')->credit);
    }

    public function test_additional_service_charge_is_a_freestanding_line_posted_to_other_income(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // grand_total 100000

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::ADDITIONAL_SERVICE_CHARGE->value,
            'items' => [
                ['description' => 'Expedited handling fee', 'amount' => 15000],
            ],
        ]);

        $this->assertEquals(0, (float) $debitNote->subtotal_goods);
        $this->assertEquals(15000, (float) $debitNote->subtotal_other);
        $this->assertNull($debitNote->items->first()->invoice_item_id);
        $this->assertEquals('Expedited handling fee', $debitNote->items->first()->description);

        $debitNote = $this->debitNoteService->submit($debitNote);

        $journalEntry = JournalEntry::query()->where('reference_type', 'debit_note')->where('reference_id', $debitNote->id)->firstOrFail();
        $lines = $journalEntry->lines()->with('chartOfAccount')->get();

        $this->assertCount(2, $lines);
        $this->assertEquals(15000, (float) $lines->firstWhere('chartOfAccount.code', '4100')->credit);
        $this->assertEquals(15000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->debit);
    }

    public function test_freestanding_line_without_description_is_rejected(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);

        $this->expectException(BusinessException::class);
        $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::FREIGHT_ADJUSTMENT->value,
            'items' => [['amount' => 5000]],
        ]);
    }

    public function test_tax_adjustment_debit_note_has_no_lines_and_posts_only_the_tax_leg(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 10000); // grand_total 110000

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::TAX_ADJUSTMENT->value,
            'tax_amount' => 5000,
        ]);

        $this->assertEquals(0, (float) $debitNote->subtotal_goods);
        $this->assertEquals(0, (float) $debitNote->subtotal_other);
        $this->assertEquals(5000, (float) $debitNote->total_amount);

        $debitNote = $this->debitNoteService->submit($debitNote);

        $journalEntry = JournalEntry::query()->where('reference_type', 'debit_note')->where('reference_id', $debitNote->id)->firstOrFail();
        $lines = $journalEntry->lines()->with('chartOfAccount')->get();

        $this->assertCount(2, $lines);
        $this->assertEquals(5000, (float) $lines->firstWhere('chartOfAccount.code', '2100')->credit);
        $this->assertEquals(5000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->debit);
    }

    public function test_tax_adjustment_with_lines_is_rejected(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $this->expectException(BusinessException::class);
        $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::TAX_ADJUSTMENT->value,
            'tax_amount' => 5000,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'amount' => 1000]],
        ]);
    }

    /**
     * Unlike Credit Note, there is no ceiling — both notes succeed and the
     * receivable reflects the sum of both. See docs/DEBIT_NOTE_DESIGN.md §0/§7.
     */
    public function test_multiple_debit_notes_are_additive_with_no_ceiling(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000); // grand_total 200000
        $invoiceItem = $invoice->items->first();

        $first = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::UNDER_BILLED_INVOICE->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_adjusted' => 2, 'amount' => 40000]],
        ]);
        $this->debitNoteService->submit($first);

        $second = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::ADDITIONAL_SERVICE_CHARGE->value,
            'items' => [['description' => 'Installation fee', 'amount' => 25000]],
        ]);
        $this->debitNoteService->submit($second);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(265000, (float) $accountsReceivable->amount); // 200000 + 40000 + 25000
        $this->assertEquals(65000, (float) $accountsReceivable->debited_amount);
    }

    public function test_debit_note_against_an_already_fully_paid_invoice_flips_status_back_to_partially_paid(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // grand_total 100000
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();

        app(AccountsReceivableService::class)->settle($accountsReceivable, 100000);
        $this->assertEquals(AccountsReceivableStatus::PAID, $accountsReceivable->fresh()->status);

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::FREIGHT_ADJUSTMENT->value,
            'items' => [['description' => 'Freight — express courier', 'amount' => 20000]],
        ]);
        $this->debitNoteService->submit($debitNote);

        $accountsReceivable->refresh();
        $this->assertEquals(120000, (float) $accountsReceivable->amount); // 100000 + 20000
        $this->assertEquals(100000, (float) $accountsReceivable->paid_amount); // untouched
        // 100000 paid < 120000 owed, so no longer PAID — newly outstanding again, the entire point of a Debit Note.
        $this->assertEquals(AccountsReceivableStatus::PARTIALLY_PAID, $accountsReceivable->status);
    }

    /**
     * A Debit Note raises the same `accounts_receivable.amount` a Credit
     * Note's own submit()-time guard (AccountsReceivableService::
     * assertWithinCreditableBalance()) reads live — no special-casing in
     * either service, they meet only at the shared row. Exercised directly
     * at the AR-service layer (rather than through CreditNoteService,
     * which is frozen this sprint) to isolate exactly the claim being made.
     */
    public function test_debit_note_raises_the_balance_a_credit_notes_ceiling_check_reads_live(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // grand_total 100000
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::PRICE_CORRECTION->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'amount' => 20000]],
        ]);
        $this->debitNoteService->submit($debitNote);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(120000, (float) $accountsReceivable->amount); // 100000 + 20000, not just the original grand_total

        // Would have thrown against the pre-Debit-Note balance of 100000 — not throwing here
        // proves the guard is reading the live, Debit-Note-raised amount.
        app(AccountsReceivableService::class)->assertWithinCreditableBalance($accountsReceivable, 120000);
        $this->addToAssertionCount(1);
    }

    public function test_reverse_restores_receivable_and_posts_swapped_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // grand_total 100000
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::UNDER_BILLED_INVOICE->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_adjusted' => 2, 'amount' => 40000]],
        ]);
        $debitNote = $this->debitNoteService->submit($debitNote);

        $originalJournal = JournalEntry::query()->where('reference_type', 'debit_note')->where('reference_id', $debitNote->id)->firstOrFail();

        $reversed = $this->debitNoteService->reverse($debitNote);

        $this->assertTrue($reversed->is_reversed);
        $this->assertNotNull($reversed->reversed_at);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(100000, (float) $accountsReceivable->amount);
        $this->assertEquals(0, (float) $accountsReceivable->debited_amount);

        $originalJournal->refresh();
        $this->assertEquals(DocumentStatus::SUBMITTED, $originalJournal->status);
        $this->assertNotNull($originalJournal->reversed_by_id);

        $reversalJournal = JournalEntry::query()->findOrFail($originalJournal->reversed_by_id);
        $reversalLines = $reversalJournal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(40000, (float) $reversalLines->firstWhere('chartOfAccount.code', '4000')->debit);
        $this->assertEquals(40000, (float) $reversalLines->firstWhere('chartOfAccount.code', '1200')->credit);
    }

    public function test_reverse_twice_throws(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::PRICE_CORRECTION->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'amount' => 20000]],
        ]);
        $debitNote = $this->debitNoteService->submit($debitNote);
        $this->debitNoteService->reverse($debitNote);

        try {
            $this->debitNoteService->reverse($debitNote->fresh());
            $this->fail('Expected reversing an already-reversed Debit Note to throw.');
        } catch (BusinessException) {
        }

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(100000, (float) $accountsReceivable->amount);
    }

    public function test_deleting_a_draft_debit_note_removes_it(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::PRICE_CORRECTION->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'amount' => 20000]],
        ]);

        $this->debitNoteService->delete($debitNote);

        $this->assertSoftDeleted('debit_notes', ['id' => $debitNote->id]);
    }

    public function test_a_submitted_debit_note_cannot_be_updated_deleted_or_resubmitted(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::PRICE_CORRECTION->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'amount' => 20000]],
        ]);
        $debitNote = $this->debitNoteService->submit($debitNote);

        try {
            $this->debitNoteService->update($debitNote, ['remarks' => 'changed']);
            $this->fail('Expected updating a submitted Debit Note to throw.');
        } catch (BusinessException) {
        }

        try {
            $this->debitNoteService->delete($debitNote);
            $this->fail('Expected deleting a submitted Debit Note to throw.');
        } catch (BusinessException) {
        }

        try {
            $this->debitNoteService->submit($debitNote->fresh());
            $this->fail('Expected re-submitting a submitted Debit Note to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseHas('debit_notes', ['id' => $debitNote->id, 'deleted_at' => null]);
    }

    /** DebitNote::cancel() must throw — reverse() is the only correction path for a submitted note. */
    public function test_debit_note_cancel_is_blocked(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::PRICE_CORRECTION->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'amount' => 20000]],
        ]);
        $debitNote = $this->debitNoteService->submit($debitNote);

        $this->expectException(BusinessException::class);
        $debitNote->cancel();
    }

    public function test_debit_note_submission_rolls_back_completely_if_journal_posting_fails(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::UNDER_BILLED_INVOICE->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_adjusted' => 2, 'amount' => 40000]],
        ]);

        ChartOfAccount::query()->where('code', '4000')->update(['is_active' => false]);

        try {
            $this->debitNoteService->submit($debitNote);
            $this->fail('Expected submit() to throw when the required chart of account is inactive.');
        } catch (BusinessException) {
        }

        $this->assertEquals(DocumentStatus::DRAFT, $debitNote->fresh()->status);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(100000, (float) $accountsReceivable->amount);
        $this->assertEquals(0, (float) $accountsReceivable->debited_amount);
        // Only the Invoice's own journal entry should exist — none for the failed Debit Note.
        $this->assertDatabaseCount('journal_entries', 1);
    }

    /**
     * Two sequential (simulating racing) submit() calls against the same
     * Invoice's AR row — unlike Credit Note's equivalent test, BOTH succeed
     * (no ceiling to violate); the row lock instead prevents a lost update
     * on the increment. See docs/DEBIT_NOTE_DESIGN.md §4/§7.
     */
    public function test_two_concurrent_submits_against_the_same_invoice_do_not_lose_either_increment(): void
    {
        $invoice = $this->submittedInvoice(qty: 2, rate: 20000); // grand_total 40000
        $invoiceItem = $invoice->items->first();

        $first = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::PRICE_CORRECTION->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'amount' => 10000]],
        ]);
        $second = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::ADDITIONAL_SERVICE_CHARGE->value,
            'items' => [['description' => 'Handling fee', 'amount' => 15000]],
        ]);

        $this->debitNoteService->submit($first);
        $this->debitNoteService->submit($second);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(65000, (float) $accountsReceivable->amount); // 40000 + 10000 + 15000, neither lost
        $this->assertEquals(25000, (float) $accountsReceivable->debited_amount);
    }

    /**
     * End-to-end ledger-balance proof, same shape as CreditNoteTest's own
     * chain test — after Invoice -> Debit Note -> Reverse, every posted
     * Journal Entry's debits still equal its credits, and 4000 nets back
     * to only the original Invoice's revenue once the Debit Note reverses.
     */
    public function test_accounting_stays_balanced_across_a_full_invoice_debit_note_reverse_chain(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 5000); // grand_total 105000
        $invoiceItem = $invoice->items->first();

        $debitNote = $this->debitNoteService->create([
            'invoice_id' => $invoice->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => DebitNoteReason::UNDER_BILLED_INVOICE->value,
            'tax_amount' => 2000,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_adjusted' => 2, 'amount' => 40000]],
        ]);
        $debitNote = $this->debitNoteService->submit($debitNote);

        foreach (JournalEntry::all() as $journalEntry) {
            $this->assertEquals(
                round((float) $journalEntry->total_debit, 2),
                round((float) $journalEntry->total_credit, 2),
                "Journal Entry {$journalEntry->document_number} is not balanced.",
            );
        }

        $this->debitNoteService->reverse($debitNote);

        foreach (JournalEntry::all() as $journalEntry) {
            $this->assertEquals(
                round((float) $journalEntry->total_debit, 2),
                round((float) $journalEntry->total_credit, 2),
                "Journal Entry {$journalEntry->document_number} is not balanced after reversal.",
            );
        }

        // 4000 Sales Revenue nets back to just the original Invoice's subtotal once the Debit Note reverses.
        $salesRevenueAccountId = $this->accountId('4000');
        $netSalesRevenue = JournalEntryLine::query()->where('chart_of_account_id', $salesRevenueAccountId)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
            ->value('net');
        $this->assertEquals(100000, (float) $netSalesRevenue); // invoice subtotal only, debit note's +40000 reversed back out

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(105000, (float) $accountsReceivable->amount);
    }
}
