<?php

namespace Tests\Feature;

use App\Enums\BankReconciliationStatus;
use App\Enums\BankStatementStatus;
use App\Models\BankReconciliationSummary;
use App\Models\BankStatement;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\PaymentEntry;
use App\Models\ReceiptEntry;
use App\Models\User;
use App\Services\BankStatement\BankReconciliationService;
use App\Services\PaymentEntryService;
use App\Services\ReceiptEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bank Reconciliation combines every cash/bank account into one bucket -- confirmed with the
 * user, since the mutasi/journal-list files it compares have no reliable structured "which
 * account" field to split on (see 2026_09_27_000003_drop_bank_account_id_from_bank_reconciliation.php).
 *
 * The "system side" is sourced from real Cash Book Transaction journal entries (via
 * BankReconciliationRepository::cashBookRows(), reusing JournalListRepository), not raw
 * receipt_entries/payment_entries rows -- so fixtures here go through ReceiptEntryService/
 * PaymentEntryService::submit() (which post the journal entry), same as JournalListExportTest,
 * rather than calling the model's own ->submit() directly.
 *
 * Amount convention is bank-statement style (debit = uang keluar, credit = uang masuk): a
 * Payment Voucher's cash leg lands in 'debit'/'kredit'=0, an Official Receipt's in 'kredit'.
 */
class BankReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BankReconciliationService $service;
    private ReceiptEntryService $receiptEntryService;
    private PaymentEntryService $paymentEntryService;
    private ChartOfAccount $bankAccount;
    private ChartOfAccount $expenseAccount;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->service = app(BankReconciliationService::class);
        $this->receiptEntryService = app(ReceiptEntryService::class);
        $this->paymentEntryService = app(PaymentEntryService::class);

        $this->bankAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $this->expenseAccount = ChartOfAccount::query()->where('code', '6000')->firstOrFail();
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
    }

    private function submittedReceipt(string $date, float $amount): ReceiptEntry
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => $date,
            'cash_account_id' => $this->bankAccount->id,
            'total_amount' => $amount,
        ]);
        $this->receiptEntryService->submit($receipt);

        return $receipt->fresh();
    }

    private function submittedPayment(string $date, float $amount): PaymentEntry
    {
        $payment = $this->paymentEntryService->create([
            'payment_type' => 'general_expense',
            'expense_account_id' => $this->expenseAccount->id,
            'description' => 'Biaya operasional',
            'payment_date' => $date,
            'cash_account_id' => $this->bankAccount->id,
            'amount' => $amount,
        ]);
        $this->paymentEntryService->submit($payment);

        return $payment->fresh();
    }

    private function statementWithLines(string $date, array $lines): BankStatement
    {
        $statement = BankStatement::query()->create([
            'format_template' => 'bca',
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => 'test.csv',
            'status' => BankStatementStatus::PROCESSED,
            'period_start' => $date,
            'period_end' => $date,
        ]);

        foreach ($lines as $line) {
            $statement->lines()->create(array_merge(['transaction_date' => $date], $line));
        }

        return $statement;
    }

    public function test_recompute_summary_marks_not_uploaded_when_no_statement_covers_the_date(): void
    {
        $this->submittedReceipt('2026-09-05', 500000);

        $this->service->recomputeSummary('2026-09-05', '2026-09-05');

        $summary = BankReconciliationSummary::query()->whereDate('date', '2026-09-05')->firstOrFail();
        $this->assertSame(BankReconciliationStatus::NOT_UPLOADED, $summary->status);
    }

    public function test_recompute_summary_is_balanced_when_system_and_statement_totals_agree(): void
    {
        $this->submittedReceipt('2026-09-01', 1500000);
        // Official Receipt = uang masuk -> statement's credit_amount (bank-statement convention).
        $this->statementWithLines('2026-09-01', [
            ['description' => 'transfer masuk', 'debit_amount' => 0, 'credit_amount' => 1500000],
        ]);

        $this->service->recomputeSummary('2026-09-01', '2026-09-01');

        $summary = BankReconciliationSummary::query()->whereDate('date', '2026-09-01')->firstOrFail();
        $this->assertSame(BankReconciliationStatus::BALANCED, $summary->status);
        $this->assertEquals(1500000, (float) $summary->system_credit_total);
        $this->assertEquals(0, (float) $summary->variance_credit);
    }

    /**
     * BCA's PostDate carries a real time-of-day (e.g. "14:24:27"); two lines on the same
     * calendar day but different times must still sum into one day's statement total, not
     * silently drop one another because GROUP BY grouped by the full timestamp instead of
     * just the date.
     */
    public function test_recompute_summary_sums_same_day_lines_with_different_times_of_day(): void
    {
        $this->submittedReceipt('2026-09-01', 23429612);
        $this->statementWithLines('2026-09-01', [
            ['description' => 'transfer 1', 'transaction_date' => '2026-09-01 14:24:27', 'debit_amount' => 0, 'credit_amount' => 18429612],
            ['description' => 'transfer 2', 'transaction_date' => '2026-09-01 15:27:32', 'debit_amount' => 0, 'credit_amount' => 5000000],
        ]);

        $this->service->recomputeSummary('2026-09-01', '2026-09-01');

        $summary = BankReconciliationSummary::query()->whereDate('date', '2026-09-01')->firstOrFail();
        $this->assertEquals(23429612, (float) $summary->statement_credit_total);
        $this->assertSame(BankReconciliationStatus::BALANCED, $summary->status);
    }

    public function test_recompute_summary_is_unbalanced_when_totals_differ(): void
    {
        $this->submittedReceipt('2026-09-01', 1500000);
        $this->statementWithLines('2026-09-01', [
            ['description' => 'transfer masuk', 'debit_amount' => 0, 'credit_amount' => 1000000],
        ]);

        $this->service->recomputeSummary('2026-09-01', '2026-09-01');

        $summary = BankReconciliationSummary::query()->whereDate('date', '2026-09-01')->firstOrFail();
        $this->assertSame(BankReconciliationStatus::UNBALANCED, $summary->status);
        $this->assertEquals(500000, (float) $summary->variance_credit);
    }

    /**
     * A day nothing ever recomputed for (no PV/OR, no statement upload) has no persisted
     * BankReconciliationSummary row at all -- that must still surface as one not_uploaded row,
     * not be silently missing from the Dashboard's daily table.
     */
    public function test_getDailyBalancingSummary_synthesizes_one_not_uploaded_row_for_a_day_with_no_recomputed_row(): void
    {
        $rows = $this->service->getDailyBalancingSummary('2026-09-26', '2026-09-26');

        $this->assertCount(1, $rows);
        $this->assertSame(BankReconciliationStatus::NOT_UPLOADED, $rows->first()->status);
    }

    public function test_getDailyBalancingSummary_spans_the_whole_date_range(): void
    {
        $rows = $this->service->getDailyBalancingSummary('2026-09-01', '2026-09-03');

        $this->assertCount(3, $rows);
        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-03'], $rows->map(fn ($r) => $r->date->format('Y-m-d'))->all());
    }

    public function test_dayDetail_returns_uploaded_files(): void
    {
        $uploader = User::factory()->create(['name' => 'Budi']);
        $statement = BankStatement::query()->create([
            'format_template' => 'bca',
            'original_filename' => 'mutasi-september.csv',
            'disk' => 'local',
            'file_path' => 'test.csv',
            'status' => BankStatementStatus::PROCESSED,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-01',
            'created_by' => $uploader->id,
        ]);
        $statement->lines()->create([
            'transaction_date' => '2026-09-01',
            'description' => 'transfer masuk',
            'debit_amount' => 0,
            'credit_amount' => 1500000,
        ]);

        $detail = $this->service->dayDetail('2026-09-01');

        $this->assertCount(1, $detail['files']);
        $this->assertSame('mutasi-september.csv', $detail['files'][0]['original_filename']);
        $this->assertSame('Budi', $detail['files'][0]['uploaded_by']);
    }

    public function test_dayDetail_files_empty_when_nothing_uploaded_for_that_day(): void
    {
        $detail = $this->service->dayDetail('2026-09-01');

        $this->assertSame([], $detail['files']);
    }

    public function test_comparisonRows_returns_cash_book_rows_from_journal_with_totals(): void
    {
        $receipt = $this->submittedReceipt('2026-09-01', 1500000);
        $payment = $this->submittedPayment('2026-09-01', 200000);

        $result = $this->service->comparisonRows('2026-09-01');

        $this->assertCount(2, $result['rows']);

        $receiptRow = collect($result['rows'])->firstWhere('tipe', 'masuk');
        $this->assertSame($receipt->document_number, $receiptRow['document_number']);
        $this->assertSame(1500000.0, $receiptRow['kredit']);
        $this->assertSame(0.0, $receiptRow['debit']);
        $this->assertStringContainsString('Acme', $receiptRow['keterangan']);

        $paymentRow = collect($result['rows'])->firstWhere('tipe', 'keluar');
        $this->assertSame($payment->document_number, $paymentRow['document_number']);
        $this->assertSame(200000.0, $paymentRow['debit']);
        $this->assertSame(0.0, $paymentRow['kredit']);

        $this->assertSame(200000.0, $result['totals']['debit']);
        $this->assertSame(1500000.0, $result['totals']['kredit']);
        $this->assertSame(1300000.0, $result['totals']['selisih']);
    }

    public function test_comparisonRows_only_includes_the_cash_bank_leg_not_the_contra_account(): void
    {
        $this->submittedReceipt('2026-09-01', 1500000);

        $result = $this->service->comparisonRows('2026-09-01');

        // Two journal lines exist for the receipt (bank leg + Unapplied Customer Payments contra
        // leg), but only the is_cash_bank one is surfaced here.
        $this->assertCount(1, $result['rows']);
    }

    public function test_comparisonRows_bank_mutasi_summary_uses_saldo_from_surrounding_lines(): void
    {
        $this->statementWithLines('2026-08-31', [
            ['description' => 'prev', 'debit_amount' => 0, 'credit_amount' => 100000, 'running_balance' => 5000000],
        ]);
        $this->statementWithLines('2026-09-01', [
            ['description' => 'masuk', 'debit_amount' => 0, 'credit_amount' => 1500000, 'running_balance' => 6500000],
            ['description' => 'keluar', 'debit_amount' => 200000, 'credit_amount' => 0, 'running_balance' => 6300000],
        ]);

        $result = $this->service->comparisonRows('2026-09-01');

        $this->assertEquals(5000000, $result['bank_mutasi']['saldo_awal']);
        $this->assertEquals(1500000, $result['bank_mutasi']['total_masuk']);
        $this->assertEquals(200000, $result['bank_mutasi']['total_keluar']);
        $this->assertEquals(6300000, $result['bank_mutasi']['saldo_akhir']);
    }

    public function test_comparisonRows_saldo_awal_is_null_when_no_earlier_statement_exists(): void
    {
        $this->statementWithLines('2026-09-01', [
            ['description' => 'masuk', 'debit_amount' => 0, 'credit_amount' => 100000, 'running_balance' => 100000],
        ]);

        $result = $this->service->comparisonRows('2026-09-01');

        $this->assertNull($result['bank_mutasi']['saldo_awal']);
    }

    public function test_comparisonRows_marks_kredit_balanced_within_rp1000_tolerance(): void
    {
        $this->submittedReceipt('2026-09-01', 1500000);
        $this->statementWithLines('2026-09-01', [
            ['description' => 'masuk dipotong biaya admin', 'debit_amount' => 0, 'credit_amount' => 1499500],
        ]);

        $result = $this->service->comparisonRows('2026-09-01');

        $this->assertSame(BankReconciliationStatus::BALANCED, $result['comparison']['kredit']['status']);
        $this->assertEquals(500, $result['comparison']['kredit']['variance']);
    }

    public function test_comparisonRows_marks_debit_unbalanced_beyond_rp1000_tolerance(): void
    {
        $this->submittedPayment('2026-09-01', 200000);
        $this->statementWithLines('2026-09-01', [
            ['description' => 'keluar', 'debit_amount' => 195000, 'credit_amount' => 0],
        ]);

        $result = $this->service->comparisonRows('2026-09-01');

        $this->assertSame(BankReconciliationStatus::UNBALANCED, $result['comparison']['debit']['status']);
        $this->assertEquals(5000, $result['comparison']['debit']['variance']);
    }
}
