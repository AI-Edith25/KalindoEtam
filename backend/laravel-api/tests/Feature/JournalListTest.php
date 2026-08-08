<?php

namespace Tests\Feature;

use App\Enums\CashBankCategory;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Services\JournalEntryService;
use App\Services\JournalListService;
use App\Services\PaymentEntryService;
use App\Services\ReceiptEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalListTest extends TestCase
{
    use RefreshDatabase;

    protected ReceiptEntryService $receiptEntryService;
    protected PaymentEntryService $paymentEntryService;
    protected JournalEntryService $journalEntryService;
    protected JournalListService $journalListService;
    protected Customer $customer;
    protected ChartOfAccount $bankAccount;
    protected ChartOfAccount $expenseAccount;
    protected Branch $branchA;
    protected Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->receiptEntryService = app(ReceiptEntryService::class);
        $this->paymentEntryService = app(PaymentEntryService::class);
        $this->journalEntryService = app(JournalEntryService::class);
        $this->journalListService = app(JournalListService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branchA = Branch::query()->create(['company_id' => $company->id, 'name' => 'Branch A', 'code' => 'BA']);
        $this->branchB = Branch::query()->create(['company_id' => $company->id, 'name' => 'Branch B', 'code' => 'BB']);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        // Seeded 'Cash and Bank' (1100) — left unclassified in the seeder deliberately, per the
        // "no hardcoded/assumed classification" rule; tests classify it explicitly where needed.
        $this->bankAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $this->expenseAccount = ChartOfAccount::query()->where('code', '6000')->firstOrFail();
    }

    protected function submittedReceipt(float $amount, ?string $branchId = null, ?ChartOfAccount $cashAccount = null): void
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => ($cashAccount ?? $this->bankAccount)->id,
            'branch_id' => $branchId,
            'total_amount' => $amount,
        ]);

        $this->receiptEntryService->submit($receipt);
    }

    protected function submittedPayment(float $amount, ?string $branchId = null, ?ChartOfAccount $cashAccount = null): void
    {
        $payment = $this->paymentEntryService->create([
            'payment_type' => 'general_expense',
            'expense_account_id' => $this->expenseAccount->id,
            'description' => 'Office supplies',
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => ($cashAccount ?? $this->bankAccount)->id,
            'branch_id' => $branchId,
        ]);

        $this->paymentEntryService->submit($payment);
    }

    public function test_receipt_entry_groups_under_its_accounts_category_and_receipt_direction(): void
    {
        $this->bankAccount->update(['cash_bank_category' => CashBankCategory::CASH_BOOK]);
        $this->submittedReceipt(50000);

        $result = $this->journalListService->summarize([]);

        $group = collect($result['groups'])->firstWhere('key', 'Cash Book-Receipt');
        $this->assertNotNull($group);
        $this->assertCount(1, $group['rows']);
        $this->assertEquals(50000, $group['subtotal']['debit']);
        $this->assertEquals(0, $group['subtotal']['credit']);
    }

    public function test_payment_entry_groups_under_payment_direction(): void
    {
        $this->bankAccount->update(['cash_bank_category' => CashBankCategory::CASH_BOOK]);
        $this->submittedPayment(15000);

        $result = $this->journalListService->summarize([]);

        $group = collect($result['groups'])->firstWhere('key', 'Cash Book-Payment');
        $this->assertNotNull($group);
        $this->assertCount(1, $group['rows']);
        $this->assertEquals(15000, $group['subtotal']['credit']);
        $this->assertEquals(0, $group['subtotal']['debit']);
    }

    public function test_unclassified_cash_account_falls_back_to_its_own_name(): void
    {
        // cash_bank_category left null — no classification made.
        $this->submittedReceipt(20000);

        $result = $this->journalListService->summarize([]);

        $group = collect($result['groups'])->firstWhere('key', "{$this->bankAccount->name}-Receipt");
        $this->assertNotNull($group);
        $this->assertEquals(20000, $group['subtotal']['debit']);
    }

    public function test_group_subtotal_sums_every_row_in_that_group(): void
    {
        $this->bankAccount->update(['cash_bank_category' => CashBankCategory::CASH_BOOK]);
        $this->submittedReceipt(10000);
        $this->submittedReceipt(30000);

        $result = $this->journalListService->summarize([]);

        $group = collect($result['groups'])->firstWhere('key', 'Cash Book-Receipt');
        $this->assertCount(2, $group['rows']);
        $this->assertEquals(40000, $group['subtotal']['debit']);
    }

    public function test_branch_filter_narrows_to_the_source_documents_own_branch(): void
    {
        $this->bankAccount->update(['cash_bank_category' => CashBankCategory::CASH_BOOK]);
        $this->submittedReceipt(10000, $this->branchA->id);
        $this->submittedReceipt(25000, $this->branchB->id);

        $result = $this->journalListService->summarize(['branch_id' => $this->branchA->id]);

        $group = collect($result['groups'])->firstWhere('key', 'Cash Book-Receipt');
        $this->assertCount(1, $group['rows']);
        $this->assertEquals(10000, $group['subtotal']['debit']);
    }

    public function test_branch_filter_excludes_a_manual_journal_entry_with_no_resolvable_branch(): void
    {
        $this->bankAccount->update(['cash_bank_category' => CashBankCategory::CASH_BOOK]);

        // A manual JE touching a cash/bank account has no source document, so no branch —
        // must not leak into a specific-branch filter's results.
        $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'description' => 'Manual cash top-up',
            'lines' => [
                ['chart_of_account_id' => $this->bankAccount->id, 'debit' => 5000, 'credit' => 0],
                ['chart_of_account_id' => $this->expenseAccount->id, 'debit' => 0, 'credit' => 5000],
            ],
        ]);
        $manual = \App\Models\JournalEntry::query()->latest('created_at')->first();
        $this->approveDocument($manual);
        $this->journalEntryService->post($manual);

        $this->submittedReceipt(10000, $this->branchA->id);

        $result = $this->journalListService->summarize(['branch_id' => $this->branchA->id]);
        $totalRows = collect($result['groups'])->sum(fn (array $group) => count($group['rows']));

        $this->assertEquals(1, $totalRows);
    }

    public function test_transfer_between_two_cash_bank_accounts_produces_two_separate_group_rows_not_a_double_count(): void
    {
        $this->bankAccount->update(['cash_bank_category' => CashBankCategory::CASH_BOOK]);
        $kas = ChartOfAccount::query()->create([
            'code' => '1000', 'name' => 'Kas', 'account_type' => 'asset',
            'is_active' => true, 'is_cash_bank' => true, 'cash_bank_category' => CashBankCategory::PETTY_CASH,
        ]);

        // Dr Kas (Petty Cash receives) / Cr Bank (Cash Book pays out) — a manual transfer.
        $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'description' => 'Transfer to petty cash',
            'lines' => [
                ['chart_of_account_id' => $kas->id, 'debit' => 20000, 'credit' => 0],
                ['chart_of_account_id' => $this->bankAccount->id, 'debit' => 0, 'credit' => 20000],
            ],
        ]);
        $transfer = \App\Models\JournalEntry::query()->latest('created_at')->first();
        $this->approveDocument($transfer);
        $this->journalEntryService->post($transfer);

        $result = $this->journalListService->summarize([]);

        $pettyCashReceipt = collect($result['groups'])->firstWhere('key', 'Petty Cash-Receipt');
        $cashBookPayment = collect($result['groups'])->firstWhere('key', 'Cash Book-Payment');

        $this->assertNotNull($pettyCashReceipt);
        $this->assertNotNull($cashBookPayment);
        $this->assertEquals(20000, $pettyCashReceipt['subtotal']['debit']);
        $this->assertEquals(20000, $cashBookPayment['subtotal']['credit']);
        $this->assertStringContainsString('Cash and Bank', $pettyCashReceipt['rows'][0]['particulars']);
        $this->assertStringContainsString('Kas', $cashBookPayment['rows'][0]['particulars']);
    }
}
