<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\PaymentEntry;
use App\Models\ReceiptEntry;
use App\Models\Supplier;
use App\Services\Import\CashBookImportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises CashBookImportService end-to-end against a CSV shaped like this
 * system's own Journal List > Cash Book export (JournalListExport): 4 title
 * rows, the real header, a group-label row (row 6), 2-line groups (one cash
 * leg + one 1150/1250/expense leg), a "Total For :[...]" trailer. "Cash
 * Book" (all) mixes a Receipt group and 2 Payment groups in one file —
 * direction is auto-detected per group from which side the cash leg sits
 * on, not fixed per file (see class docblock).
 */
class CashBookImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CashBookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->service = app(CashBookImportService::class);
    }

    private function makeBatch(string $csv): ImportBatch
    {
        $path = 'imports/test.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'cash-book',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
        ]);
    }

    private const PREAMBLE = "JOURNAL LIST\r\nPT. KALINDO ETAM\r\n01/09/2026 - 30/09/2026,,,,17/09/2026 10:09:44\r\n\r\n";

    private const HEADER = "Transaction,Date,Notes,Particulars,Debit,Credit\r\n";

    public function test_mixed_receipt_and_payment_groups_create_the_right_documents(): void
    {
        Customer::query()->create(['customer_code' => 'C0001', 'customer_name' => 'PT ABC', 'is_active' => true]);
        Supplier::query()->create(['supplier_code' => 'S0001', 'supplier_name' => 'PT XYZ', 'is_active' => true]);

        $csv = self::PREAMBLE.self::HEADER
            .'Cash Book Transaction,,,,,'."\r\n"
            // Receipt: cash debit (sibling fallback remark), 1150 credit (own description).
            .'OR-0001,01/09/2026,,"1100 - Cash and Bank - [Unapplied Customer Payments (PT ABC; Cash and Bank)]",500000,0'."\r\n"
            .',01/09/2026,,"1150 - Unapplied Customer Payments - [PT ABC; Cash and Bank]",0,500000'."\r\n"
            // Payment (supplier): cash credit, 1250 debit — same uniform description on both lines.
            .'PV-0001,02/09/2026,,"1100 - Cash and Bank - [Payment Entry PV-0001 - PT XYZ]",0,300000'."\r\n"
            .',02/09/2026,,"1250 - Advance to Suppliers - [Payment Entry PV-0001 - PT XYZ]",300000,0'."\r\n"
            // Payment (general expense): cash credit, expense account debit — no party at all.
            .'PV-0002,03/09/2026,,"1100 - Cash and Bank - [Payment Entry PV-0002]",0,150000'."\r\n"
            .',03/09/2026,,"6100 - Beban Transport - [Payment Entry PV-0002]",150000,0'."\r\n"
            .'Total For :[Cash Book Transaction],,,,950000,950000'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        // No open Accounts Receivable/Payable exist in this test (no Invoice/Purchase Invoice was
        // created), so the Receipt and the supplier Payment are correctly "Unallocated" — same
        // needs_review-not-failed outcome OfficialReceiptImportService/PaymentVoucherImportService
        // already use for this exact case. The general-expense Payment needs no allocation at all.
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(2, $batch->preview_summary['needs_review_rows']);

        $receipt = ReceiptEntry::query()->where('reference_number', 'OR-0001')->firstOrFail();
        $this->assertEquals(500000, (float) $receipt->total_amount);
        $this->assertSame('submitted', $receipt->status->value);

        $supplierPayment = PaymentEntry::query()->where('reference_number', 'PV-0001')->firstOrFail();
        $this->assertSame('supplier', $supplierPayment->payment_type->value);
        $this->assertEquals(300000, (float) $supplierPayment->total_amount);

        $expensePayment = PaymentEntry::query()->where('reference_number', 'PV-0002')->firstOrFail();
        $this->assertSame('general_expense', $expensePayment->payment_type->value);
        $this->assertEquals(150000, (float) $expensePayment->total_amount);
        $this->assertSame('6100', $expensePayment->expenseAccount->code);
    }

    public function test_trailer_checksum_mismatch_fails_the_whole_batch(): void
    {
        Customer::query()->create(['customer_code' => 'C0001', 'customer_name' => 'PT ABC', 'is_active' => true]);

        $csv = self::PREAMBLE.self::HEADER
            .'Cash Book-Receipt,,,,,'."\r\n"
            .'OR-0001,01/09/2026,,"1100 - Cash and Bank - [Unapplied Customer Payments (PT ABC; Cash and Bank)]",500000,0'."\r\n"
            .',01/09/2026,,"1150 - Unapplied Customer Payments - [PT ABC; Cash and Bank]",0,500000'."\r\n"
            // Deliberately wrong trailer total.
            .'Total For :[Cash Book-Receipt],,,,999999,999999'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::FAILED, $batch->status);
        $this->assertStringContainsString('tidak cocok dengan baris Total', $batch->failure_reason);
        $this->assertSame(0, ReceiptEntry::query()->count());
    }

    public function test_duplicate_reference_number_is_skipped_not_reimported(): void
    {
        $cash = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $expense = ChartOfAccount::query()->where('code', '6100')->firstOrFail();

        PaymentEntry::query()->create([
            'payment_type' => 'general_expense',
            'expense_account_id' => $expense->id,
            'description' => 'Already imported once',
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $cash->id,
            'reference_number' => 'PV-0002',
            'total_amount' => 150000,
        ]);

        $csv = self::PREAMBLE.self::HEADER
            .'Cash Book-Payment,,,,,'."\r\n"
            .'PV-0002,03/09/2026,,"1100 - Cash and Bank - [Payment Entry PV-0002]",0,150000'."\r\n"
            .',03/09/2026,,"6100 - Beban Transport - [Payment Entry PV-0002]",150000,0'."\r\n"
            .'Total For :[Cash Book-Payment],,,,150000,150000'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertSame('needs_review', $vouchers[0]['status']);
        $this->assertStringContainsString('sudah pernah diimpor', $vouchers[0]['reason']);
        $this->assertSame(1, PaymentEntry::query()->where('reference_number', 'PV-0002')->count());
    }

    public function test_unmatched_supplier_is_flagged_for_review_without_blocking_the_batch(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'Cash Book-Payment,,,,,'."\r\n"
            .'PV-0003,04/09/2026,,"1100 - Cash and Bank - [Payment Entry PV-0003 - PT Unknown Corp]",0,200000'."\r\n"
            .',04/09/2026,,"1250 - Advance to Suppliers - [Payment Entry PV-0003 - PT Unknown Corp]",200000,0'."\r\n"
            .'Total For :[Cash Book-Payment],,,,200000,200000'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertSame('needs_review', $vouchers[0]['status']);
        $this->assertSame(0, PaymentEntry::query()->where('reference_number', 'PV-0003')->count());
    }

    public function test_detect_group_label_reads_the_row_after_the_header(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'Cash Book-Receipt,,,,,'."\r\n"
            .'OR-0001,01/09/2026,,"1100 - Cash and Bank - [x]",500000,0'."\r\n";

        $path = 'imports/label-test.csv';
        Storage::disk('local')->put($path, $csv);

        $label = $this->service->detectGroupLabel(Storage::disk('local')->path($path), 'csv');

        $this->assertSame('Cash Book-Receipt', $label);
    }
}
