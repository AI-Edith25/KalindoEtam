<?php

namespace Tests\Feature;

use App\Enums\BankReconciliationStatus;
use App\Enums\BankStatementLineMatchStatus;
use App\Enums\BankStatementStatus;
use App\Models\BankReconciliationSummary;
use App\Models\BankStatement;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\PaymentEntry;
use App\Models\ReceiptEntry;
use App\Services\BankStatement\BankReconciliationService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BankReconciliationService $service;
    private ChartOfAccount $bankAccount;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->service = app(BankReconciliationService::class);

        $this->bankAccount = ChartOfAccount::query()->create([
            'code' => '1101', 'name' => 'BANK BCA SMD 1312', 'account_type' => 'asset',
            'is_active' => true, 'is_cash_bank' => true, 'cash_bank_category' => 'cash_book',
        ]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
    }

    private function submittedReceipt(string $date, float $amount): ReceiptEntry
    {
        return ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => $date,
            'cash_account_id' => $this->bankAccount->id,
            'total_amount' => $amount,
            'allocated_amount' => 0,
        ])->submit();
    }

    private function submittedPayment(string $date, float $amount): PaymentEntry
    {
        return PaymentEntry::query()->create([
            'payment_type' => 'general_expense',
            'payment_date' => $date,
            'payment_method' => 'bank_transfer',
            'cash_account_id' => $this->bankAccount->id,
            'total_amount' => $amount,
        ])->submit();
    }

    private function statementWithLines(string $date, array $lines): BankStatement
    {
        $statement = BankStatement::query()->create([
            'bank_account_id' => $this->bankAccount->id,
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

    public function test_match_links_line_to_exact_amount_receipt_entry(): void
    {
        $receipt = $this->submittedReceipt('2026-09-01', 1500000);
        $statement = $this->statementWithLines('2026-09-01', [
            ['description' => 'transfer masuk', 'debit_amount' => 1500000, 'credit_amount' => 0],
        ]);

        $this->service->match($this->bankAccount->id, '2026-09-01', '2026-09-01');

        $line = $statement->lines()->first();
        $this->assertSame(BankStatementLineMatchStatus::MATCHED, $line->match_status);
        $this->assertSame($receipt->id, $line->matched_document_id);
        $this->assertSame($receipt->getMorphClass(), $line->matched_document_type);
    }

    public function test_match_links_line_to_exact_amount_payment_entry(): void
    {
        $payment = $this->submittedPayment('2026-09-01', 32000);
        $statement = $this->statementWithLines('2026-09-01', [
            ['description' => 'biaya admin', 'debit_amount' => 32000, 'credit_amount' => 0],
        ]);
        // Payment Voucher = outflow = credit to bank; flip the fixture line to credit-side to match it.
        $statement->lines()->update(['debit_amount' => 0, 'credit_amount' => 32000]);

        $this->service->match($this->bankAccount->id, '2026-09-01', '2026-09-01');

        $line = $statement->lines()->first();
        $this->assertSame(BankStatementLineMatchStatus::MATCHED, $line->match_status);
        $this->assertSame($payment->id, $line->matched_document_id);
    }

    public function test_line_with_no_matching_document_stays_unmatched(): void
    {
        $statement = $this->statementWithLines('2026-09-01', [
            ['description' => 'biaya admin', 'debit_amount' => 25000, 'credit_amount' => 0],
        ]);

        $this->service->match($this->bankAccount->id, '2026-09-01', '2026-09-01');

        $this->assertSame(BankStatementLineMatchStatus::UNMATCHED, $statement->lines()->first()->match_status);
    }

    public function test_recompute_summary_marks_not_uploaded_when_no_statement_covers_the_date(): void
    {
        $this->submittedReceipt('2026-09-05', 500000);

        $this->service->recomputeSummary($this->bankAccount->id, '2026-09-05', '2026-09-05');

        $summary = BankReconciliationSummary::query()->whereDate('date', '2026-09-05')->firstOrFail();
        $this->assertSame(BankReconciliationStatus::NOT_UPLOADED, $summary->status);
    }

    public function test_recompute_summary_is_balanced_when_system_and_statement_totals_agree(): void
    {
        $this->submittedReceipt('2026-09-01', 1500000);
        $this->statementWithLines('2026-09-01', [
            ['description' => 'transfer masuk', 'debit_amount' => 1500000, 'credit_amount' => 0],
        ]);

        $this->service->recomputeSummary($this->bankAccount->id, '2026-09-01', '2026-09-01');

        $summary = BankReconciliationSummary::query()->whereDate('date', '2026-09-01')->firstOrFail();
        $this->assertSame(BankReconciliationStatus::BALANCED, $summary->status);
        $this->assertEquals(0, (float) $summary->variance_debit);
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
            ['description' => 'transfer 1', 'transaction_date' => '2026-09-01 14:24:27', 'debit_amount' => 18429612, 'credit_amount' => 0],
            ['description' => 'transfer 2', 'transaction_date' => '2026-09-01 15:27:32', 'debit_amount' => 5000000, 'credit_amount' => 0],
        ]);

        $this->service->recomputeSummary($this->bankAccount->id, '2026-09-01', '2026-09-01');

        $summary = BankReconciliationSummary::query()->whereDate('date', '2026-09-01')->firstOrFail();
        $this->assertEquals(23429612, (float) $summary->statement_debit_total);
        $this->assertSame(BankReconciliationStatus::BALANCED, $summary->status);
    }

    public function test_recompute_summary_is_unbalanced_when_totals_differ(): void
    {
        $this->submittedReceipt('2026-09-01', 1500000);
        $this->statementWithLines('2026-09-01', [
            ['description' => 'transfer masuk', 'debit_amount' => 1000000, 'credit_amount' => 0],
        ]);

        $this->service->recomputeSummary($this->bankAccount->id, '2026-09-01', '2026-09-01');

        $summary = BankReconciliationSummary::query()->whereDate('date', '2026-09-01')->firstOrFail();
        $this->assertSame(BankReconciliationStatus::UNBALANCED, $summary->status);
        $this->assertEquals(500000, (float) $summary->variance_debit);
    }

    public function test_manual_match_sets_manual_matched_status(): void
    {
        $receipt = $this->submittedReceipt('2026-09-01', 999999);
        $statement = $this->statementWithLines('2026-09-01', [
            ['description' => 'unrecognized', 'debit_amount' => 123456, 'credit_amount' => 0],
        ]);
        $line = $statement->lines()->first();

        $this->service->manualMatch($line, $receipt->getMorphClass(), $receipt->id);

        $line->refresh();
        $this->assertSame(BankStatementLineMatchStatus::MANUAL_MATCHED, $line->match_status);
        $this->assertSame($receipt->id, $line->matched_document_id);
    }

    /**
     * A day nothing ever recomputed for (no PV/OR, no statement upload) has no persisted
     * BankReconciliationSummary row at all -- that must still surface as not_uploaded for every
     * is_cash_bank account, not be silently missing from the Dashboard's daily table.
     */
    public function test_getDailyBalancingSummary_synthesizes_not_uploaded_for_every_bank_account_with_no_recomputed_row(): void
    {
        $secondBankAccount = ChartOfAccount::query()->create([
            'code' => '1102', 'name' => 'BANK MANDIRI SMD', 'account_type' => 'asset',
            'is_active' => true, 'is_cash_bank' => true, 'cash_bank_category' => 'cash_book',
        ]);

        $rows = $this->service->getDailyBalancingSummary(null, '2026-09-26', '2026-09-26');

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($row) => $row->status === BankReconciliationStatus::NOT_UPLOADED));
        $this->assertEqualsCanonicalizing(
            [$this->bankAccount->id, $secondBankAccount->id],
            $rows->pluck('bank_account_id')->all(),
        );
    }

    public function test_getDailyBalancingSummary_filters_synthesized_rows_by_bank_account_id(): void
    {
        ChartOfAccount::query()->create([
            'code' => '1102', 'name' => 'BANK MANDIRI SMD', 'account_type' => 'asset',
            'is_active' => true, 'is_cash_bank' => true, 'cash_bank_category' => 'cash_book',
        ]);

        $rows = $this->service->getDailyBalancingSummary($this->bankAccount->id, '2026-09-26', '2026-09-26');

        $this->assertCount(1, $rows);
        $this->assertSame($this->bankAccount->id, $rows->first()->bank_account_id);
    }

    public function test_importRows_shows_matched_documents_customer_and_system_amount(): void
    {
        $receipt = $this->submittedReceipt('2026-09-01', 1500000);
        $statement = $this->statementWithLines('2026-09-01', [
            ['description' => 'transfer masuk', 'debit_amount' => 1500000, 'credit_amount' => 0],
        ]);
        $this->service->match($this->bankAccount->id, '2026-09-01', '2026-09-01');

        $rows = $this->service->importRows($this->bankAccount->id, '2026-09-01');

        $this->assertCount(1, $rows);
        $this->assertSame('Acme', $rows[0]['customer']);
        $this->assertSame(1500000.0, $rows[0]['system_amount']);
        $this->assertSame(1500000.0, $rows[0]['statement_amount']);
        $this->assertSame(0.0, $rows[0]['selisih']);
        $this->assertSame('matched', $rows[0]['status']);
    }

    public function test_importRows_leaves_customer_and_system_amount_null_when_unmatched(): void
    {
        $this->statementWithLines('2026-09-01', [
            ['description' => 'biaya admin', 'debit_amount' => 25000, 'credit_amount' => 0],
        ]);

        $rows = $this->service->importRows($this->bankAccount->id, '2026-09-01');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['customer']);
        $this->assertNull($rows[0]['system_amount']);
        $this->assertSame(25000.0, $rows[0]['selisih']);
        $this->assertSame('unmatched', $rows[0]['status']);
    }

    public function test_systemRows_shows_matched_statement_amount_and_unmatched_document(): void
    {
        $receipt = $this->submittedReceipt('2026-09-01', 1500000);
        $this->submittedPayment('2026-09-01', 900901);
        $this->statementWithLines('2026-09-01', [
            ['description' => 'transfer masuk', 'debit_amount' => 1500000, 'credit_amount' => 0],
        ]);
        $this->service->match($this->bankAccount->id, '2026-09-01', '2026-09-01');

        $rows = collect($this->service->systemRows($this->bankAccount->id, '2026-09-01'));

        $matchedRow = $rows->firstWhere('id', $receipt->id);
        $this->assertSame('Acme', $matchedRow['customer']);
        $this->assertSame(1500000.0, $matchedRow['statement_amount']);
        $this->assertSame(0.0, $matchedRow['selisih']);
        $this->assertSame('matched', $matchedRow['status']);

        $unmatchedRow = $rows->firstWhere('statement_amount', null);
        $this->assertSame(900901.0, $unmatchedRow['system_amount']);
        $this->assertSame('unmatched', $unmatchedRow['status']);
    }
}
