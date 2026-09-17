<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\PaymentEntry;
use App\Models\Supplier;
use App\Services\Import\PaymentVoucherImportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises the smart importer end-to-end (parse -> auto-detect header ->
 * group by DOCUMENT # -> match -> create/submit) against a CSV shaped like
 * the real legacy export: 4 title rows to skip, then the real header, then
 * a mix of 2-line and 3-line voucher groups. Doesn't cover the full
 * supplier-AP-allocation path (that needs a real GoodsReceipt->PurchaseInvoice
 * chain — see PaymentEntryMixedTest) since a voucher with no matching open
 * AP is itself a first-class outcome here (Unallocated), not a gap.
 */
class PaymentVoucherImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentVoucherImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->service = app(PaymentVoucherImportService::class);
    }

    private function makeBatch(string $csv): ImportBatch
    {
        $path = 'imports/test.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'payment-vouchers',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
        ]);
    }

    private const PREAMBLE = "Payment Voucher Listing\r\nPeriod: 01/08/2026 - 31/08/2026\r\nPT Test Company - Printed 01/09/2026\r\n\r\n";

    private const HEADER = "DATE,DOCUMENT #,CHEQUE DATE,CHEQUE #,ACCOUNT,SL CODE,PARTICULARS,DEBIT,CREDIT,CUR.,RATE,C.DEBIT,C.CREDIT,STATUS\r\n";

    public function test_supplier_line_with_no_matching_open_ap_is_created_unallocated(): void
    {
        Supplier::query()->create(['supplier_code' => 'S-0001', 'supplier_name' => 'PT. Conch South Kalimantan Cement']);

        $csv = self::PREAMBLE.self::HEADER
            .'01/08/2026,PV/KE/00001/08/2026,,,102.01.01,,"BCA KE SMD",0.00,500000.00,IDR,1,0,500000,Approved'."\r\n"
            .'01/08/2026,PV/KE/00001/08/2026,,,210.01.01,S-0318,"HUTANG SUPPLIER, PT. Conch South Kalimantan Cement, BANK BCA",500000.00,0.00,IDR,1,500000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertCount(1, $vouchers);
        $this->assertSame('needs_review', $vouchers[0]['status']);

        $entry = PaymentEntry::query()->where('reference_number', 'PV/KE/00001/08/2026')->firstOrFail();
        $this->assertSame('supplier', $entry->payment_type->value);
        $this->assertSame('submitted', $entry->status->value);
        $this->assertEquals(500000, (float) $entry->total_amount);
        $this->assertCount(0, $entry->items);
    }

    public function test_expense_only_voucher_matches_by_account_name_and_succeeds(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'02/08/2026,PV/KE/00002/08/2026,,,102.01.01,,"Pengambilan kas kecil",0.00,150000.00,IDR,1,0,150000,Approved'."\r\n"
            .'02/08/2026,PV/KE/00002/08/2026,,,610.01.03,,"Beban Transport",150000.00,0.00,IDR,1,150000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertSame('success', $vouchers[0]['status']);
        $this->assertSame(1, $batch->success_rows);

        $entry = PaymentEntry::query()->where('reference_number', 'PV/KE/00002/08/2026')->firstOrFail();
        $this->assertSame('general_expense', $entry->payment_type->value);
        $this->assertSame('submitted', $entry->status->value);
        $this->assertSame('6100', $entry->expenseAccount->code);
    }

    public function test_three_row_group_becomes_mixed_with_partial_match_flagged_for_review(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'03/08/2026,PV/KE/00003/08/2026,,BCA KE SMD,102.01.01,,"Pembayaran gabungan",0.00,140000.00,IDR,1,0,140000,Approved'."\r\n"
            .'03/08/2026,PV/KE/00003/08/2026,,,610.01.03,,"Beban Transport",40000.00,0.00,IDR,1,40000,0,Approved'."\r\n"
            .'03/08/2026,PV/KE/00003/08/2026,,,999.99.99,,"Zzz Unrecognizable Legacy Code",100000.00,0.00,IDR,1,100000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertSame('needs_review', $vouchers[0]['status']);

        $entry = PaymentEntry::query()->where('reference_number', 'PV/KE/00003/08/2026')->firstOrFail();
        $this->assertSame('mixed', $entry->payment_type->value);
        $this->assertSame('submitted', $entry->status->value);
        $this->assertCount(1, $entry->expenseLines);
        $this->assertEquals(40000, (float) $entry->expenseLines->first()->amount);
    }

    public function test_unbalanced_group_fails_without_blocking_the_rest(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'04/08/2026,PV/KE/00004/08/2026,,,102.01.01,,"Kas keluar",0.00,100000.00,IDR,1,0,100000,Approved'."\r\n"
            .'04/08/2026,PV/KE/00004/08/2026,,,610.01.03,,"Beban Transport",90000.00,0.00,IDR,1,90000,0,Approved'."\r\n"
            .'05/08/2026,PV/KE/00005/08/2026,,,102.01.01,,"Kas keluar",0.00,50000.00,IDR,1,0,50000,Approved'."\r\n"
            .'05/08/2026,PV/KE/00005/08/2026,,,610.01.03,,"Beban Transport",50000.00,0.00,IDR,1,50000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = collect($batch->preview_summary['vouchers'])->keyBy('document_number');
        $this->assertSame('failed', $vouchers['PV/KE/00004/08/2026']['status']);
        $this->assertSame('success', $vouchers['PV/KE/00005/08/2026']['status']);
        $this->assertSame(1, $batch->failed_rows);
        $this->assertSame(1, $batch->success_rows);
    }

    public function test_duplicate_reference_number_is_skipped_not_reimported(): void
    {
        PaymentEntry::query()->create([
            'payment_type' => 'general_expense',
            'expense_account_id' => ChartOfAccount::query()->where('code', '6100')->firstOrFail()->id,
            'description' => 'Already imported once',
            'payment_date' => now()->toDateString(),
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
            'reference_number' => 'PV/KE/00006/08/2026',
            'total_amount' => 75000,
        ]);

        $csv = self::PREAMBLE.self::HEADER
            .'06/08/2026,PV/KE/00006/08/2026,,,102.01.01,,"Kas keluar",0.00,75000.00,IDR,1,0,75000,Approved'."\r\n"
            .'06/08/2026,PV/KE/00006/08/2026,,,610.01.03,,"Beban Transport",75000.00,0.00,IDR,1,75000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertSame('needs_review', $vouchers[0]['status']);
        $this->assertStringContainsString('sudah pernah diimpor', $vouchers[0]['reason']);
        $this->assertSame(1, PaymentEntry::query()->where('reference_number', 'PV/KE/00006/08/2026')->count());
    }
}
