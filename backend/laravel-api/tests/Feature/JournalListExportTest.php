<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Services\PaymentEntryService;
use App\Services\ReceiptEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Journal List's export — journal-line level, reproducing the legacy
 * xlsJournalList(Cashbook/OR/PV).xlsx layout exactly (verified against the
 * real files at the project root during the Journal List rework).
 */
class JournalListExportTest extends TestCase
{
    use RefreshDatabase;

    protected ReceiptEntryService $receiptEntryService;
    protected PaymentEntryService $paymentEntryService;
    protected Customer $customer;
    protected ChartOfAccount $bankAccount;
    protected ChartOfAccount $expenseAccount;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->receiptEntryService = app(ReceiptEntryService::class);
        $this->paymentEntryService = app(PaymentEntryService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Head Office', 'code' => 'HO']);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->bankAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $this->expenseAccount = ChartOfAccount::query()->where('code', '6000')->firstOrFail();

        Permission::query()->firstOrCreate(['name' => 'accounting.journal_list.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.journal_list.view');
        Sanctum::actingAs($user);
    }

    protected function downloadXlsx(string $query): Worksheet
    {
        $response = $this->get("/api/v1/journal-list/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'journal-list').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_receipt_export_matches_the_legacy_5_row_preamble_and_group_header(): void
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-01-15',
            'cash_account_id' => $this->bankAccount->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 50000,
        ]);
        $this->receiptEntryService->submit($receipt);

        $sheet = $this->downloadXlsx('view=receipt&date_from=2026-01-01&date_to=2026-01-31');

        $this->assertEquals('JOURNAL LIST', $sheet->getCell('A1')->getValue());
        $this->assertEquals('PT. KALINDO ETAM', $sheet->getCell('A2')->getValue());
        $this->assertEquals('01/01/2026 - 31/01/2026', $sheet->getCell('A3')->getValue());
        $this->assertNotNull($sheet->getCell('E3')->getValue());
        $this->assertEquals('Transaction', $sheet->getCell('A5')->getValue());
        $this->assertEquals('Notes', $sheet->getCell('C5')->getValue());
        $this->assertEquals('Debit', $sheet->getCell('E5')->getValue());
        $this->assertEquals('Credit', $sheet->getCell('F5')->getValue());
        $this->assertNull($sheet->getCell('G5')->getValue());
        $this->assertEquals('Cash Book-Receipt', $sheet->getCell('A6')->getValue());
    }

    public function test_voucher_lines_share_date_and_ref_but_only_the_first_line_carries_the_transaction_number(): void
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-01-15',
            'cash_account_id' => $this->bankAccount->id,
            'branch_id' => $this->branch->id,
            'reference_number' => 'BCA KE',
            'total_amount' => 50000,
        ]);
        $this->receiptEntryService->submit($receipt);

        $sheet = $this->downloadXlsx('view=receipt');

        // Row 7 = first line (the cash/bank leg, debit side), row 8 = second line (Unapplied Customer
        // Payments, credit side) — same voucher, so continuation-only fields must match across both.
        $this->assertEquals($receipt->document_number, $sheet->getCell('A7')->getValue());
        $this->assertNull($sheet->getCell('A8')->getValue());
        $this->assertEquals('15/01/2026', $sheet->getCell('B7')->getValue());
        $this->assertEquals('15/01/2026', $sheet->getCell('B8')->getValue());
        $this->assertEquals('BCA KE', $sheet->getCell('C7')->getValue());
        $this->assertEquals('BCA KE', $sheet->getCell('C8')->getValue());

        // Debit/credit: the relevant side holds the amount, the other side is genuinely blank (null), never 0.
        $this->assertEquals(50000, $sheet->getCell('E7')->getValue());
        $this->assertNull($sheet->getCell('F7')->getValue());
        $this->assertNull($sheet->getCell('E8')->getValue());
        $this->assertEquals(50000, $sheet->getCell('F8')->getValue());

        // Particulars: AccountingService::postForDocument() gives every line some description (the
        // cash leg gets the entry-level default "Receipt {doc} - {customer}"; the Unapplied Customer
        // Payments leg carries its own explicit "{customer}; {cash account}" override) — so both
        // rows identify the paying customer, never an empty bracket.
        $this->assertStringContainsString('Acme', $sheet->getCell('D7')->getValue());
        $this->assertStringContainsString('Acme', $sheet->getCell('D8')->getValue());
        $this->assertStringContainsString($this->bankAccount->name, $sheet->getCell('D8')->getValue());
    }

    public function test_total_for_trailer_row_balances_debit_and_credit(): void
    {
        $receiptA = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-01-05',
            'cash_account_id' => $this->bankAccount->id, 'total_amount' => 30000,
        ]);
        $this->receiptEntryService->submit($receiptA);
        $receiptB = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-01-06',
            'cash_account_id' => $this->bankAccount->id, 'total_amount' => 70000,
        ]);
        $this->receiptEntryService->submit($receiptB);

        $sheet = $this->downloadXlsx('view=receipt');

        // 2 vouchers x 2 lines each = 4 physical rows (7-10), trailer at row 11.
        $this->assertEquals('Total For :[Cash Book-Receipt]', $sheet->getCell('A11')->getValue());
        $this->assertEquals(100000, $sheet->getCell('E11')->getValue());
        $this->assertEquals(100000, $sheet->getCell('F11')->getValue());
    }

    public function test_all_view_groups_under_cash_book_transaction_and_includes_both_receipt_and_payment(): void
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-01-05',
            'cash_account_id' => $this->bankAccount->id, 'total_amount' => 40000,
        ]);
        $this->receiptEntryService->submit($receipt);
        $payment = $this->paymentEntryService->create([
            'payment_type' => 'general_expense', 'expense_account_id' => $this->expenseAccount->id,
            'description' => 'Office supplies', 'amount' => 15000, 'payment_date' => '2026-01-06',
            'cash_account_id' => $this->bankAccount->id,
        ]);
        $this->paymentEntryService->submit($payment);

        $sheet = $this->downloadXlsx('view=all');

        $this->assertEquals('Cash Book Transaction', $sheet->getCell('A6')->getValue());
        $this->assertEquals('Total For :[Cash Book Transaction]', $sheet->getCell('A11')->getValue());
        $this->assertEquals(55000, $sheet->getCell('E11')->getValue());
        $this->assertEquals(55000, $sheet->getCell('F11')->getValue());
    }

    public function test_download_filename_follows_journallist_segment_ddmmyyyy_pattern(): void
    {
        $today = now()->format('dmY');

        $this->get('/api/v1/journal-list/export?view=all')
            ->assertOk()
            ->assertHeader('content-disposition', "attachment; filename=JournalList-Cashbook-{$today}.xlsx");

        $this->get('/api/v1/journal-list/export?view=receipt&format=csv')
            ->assertOk()
            ->assertHeader('content-disposition', "attachment; filename=JournalList-OfficialReceipt-{$today}.csv");

        $this->get('/api/v1/journal-list/export?view=payment')
            ->assertOk()
            ->assertHeader('content-disposition', "attachment; filename=JournalList-PaymentVoucher-{$today}.xlsx");
    }

    public function test_payment_voucher_export_survives_a_since_deleted_expense_account(): void
    {
        $payment = $this->paymentEntryService->create([
            'payment_type' => 'general_expense', 'expense_account_id' => $this->expenseAccount->id,
            'description' => 'Office supplies', 'amount' => 15000, 'payment_date' => '2026-01-06',
            'cash_account_id' => $this->bankAccount->id,
        ]);
        $this->paymentEntryService->submit($payment);

        // Mirrors production: a chart_of_accounts row can be soft-deleted after vouchers
        // referencing it already exist (see the 2026-09-27 cash/bank cleanup migration).
        $this->expenseAccount->delete();

        $sheet = $this->downloadXlsx('view=payment');

        $this->assertStringContainsString($this->expenseAccount->code, $sheet->getCell('D7')->getValue());
        $this->assertEquals('Total For :[Cash Book-Payment]', $sheet->getCell('A9')->getValue());
    }

    /**
     * Editing a Submitted Receipt (ReceiptEntryService::updateSubmitted()) reverses its old
     * Journal Entry and posts a fresh one — two siblings, neither pointing forward to the other,
     * so without JournalListRepository's own dedupe both the stale reversal and the final repost
     * would show up as extra rows (the real-world bug: the same voucher appearing 2-5x, some
     * rows with Debit/Credit flipped).
     */
    public function test_editing_a_submitted_receipt_does_not_duplicate_it_in_the_export(): void
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-01-15',
            'cash_account_id' => $this->bankAccount->id, 'total_amount' => 50000,
        ]);
        $receipt = $this->receiptEntryService->submit($receipt);

        $receipt = $this->receiptEntryService->update($receipt, ['total_amount' => 75000]);
        // A second edit chains a third Journal Entry on top — must still collapse to one voucher.
        $this->receiptEntryService->update($receipt, ['total_amount' => 90000]);

        $sheet = $this->downloadXlsx('view=receipt');

        // Only one voucher block (2 lines: cash/bank leg + the offsetting leg) — rows 7-8 — then
        // straight to the trailer at row 9, never a second/third copy of the same document.
        $this->assertEquals($receipt->document_number, $sheet->getCell('A7')->getValue());
        $this->assertEquals(90000, $sheet->getCell('E7')->getValue());
        $this->assertEquals('Total For :[Cash Book-Receipt]', $sheet->getCell('A9')->getValue());
        $this->assertEquals(90000, $sheet->getCell('E9')->getValue());
        $this->assertEquals(90000, $sheet->getCell('F9')->getValue());
    }

    public function test_csv_format_produces_the_same_group_header_and_trailer(): void
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-01-15',
            'cash_account_id' => $this->bankAccount->id, 'total_amount' => 50000,
        ]);
        $this->receiptEntryService->submit($receipt);

        $response = $this->get('/api/v1/journal-list/export?view=receipt&format=csv');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Cash Book-Receipt', $content);
        $this->assertStringContainsString('Total For :[Cash Book-Receipt]', $content);
        $this->assertStringContainsString($receipt->document_number, $content);
    }
}
