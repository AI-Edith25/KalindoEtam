<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ReceiptEntry;
use App\Services\Import\OfficialReceiptImportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AR mirror of PaymentVoucherImportServiceTest — same CSV shape (4 title
 * rows, DOCUMENT # groups), cash/party sides flipped (cash = DEBIT here).
 * Doesn't cover the full customer-AR-allocation path (needs a real
 * SalesOrder->Delivery->Invoice chain) since a receipt with no matching
 * open AR is itself a first-class outcome here (Unallocated), not a gap —
 * same scoping call as the Payment Voucher test suite.
 */
class OfficialReceiptImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected OfficialReceiptImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->service = app(OfficialReceiptImportService::class);
    }

    private function makeBatch(string $csv): ImportBatch
    {
        $path = 'imports/test.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'official-receipts',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
        ]);
    }

    private const PREAMBLE = "Official Receipt Listing\r\nPeriod: 01/08/2026 - 31/08/2026\r\nPT Test Company - Printed 01/09/2026\r\n\r\n";

    // Real header text per the sample file — "COLLECTION DATE" instead of Payment Voucher's
    // "CHEQUE DATE", confirming the shared field/synonym detector handles both.
    private const HEADER = "DATE,DOCUMENT #,COLLECTION DATE,CHEQUE #,ACCOUNT,SL CODE,PARTICULARS,DEBIT,CREDIT,CUR.,RATE,C.DEBIT,C.CREDIT,STATUS\r\n";

    public function test_customer_matched_via_bank_code_with_no_open_ar_is_unallocated(): void
    {
        Customer::query()->create(['customer_code' => 'C-0529', 'customer_name' => 'FAKTA JAYA']);

        $csv = self::PREAMBLE.self::HEADER
            .'18/08/2026,OR/KE/07269/08/2026,18/08/2026,bgdanamon210058,112.01.01,C-0529,"PIUTANG USAHA, FAKTA JAYA-(SMD), BANK BCA 1312",0.00,23600003.76,IDR,1,0,23600003.76,Approved'."\r\n"
            .'18/08/2026,OR/KE/07269/08/2026,18/08/2026,bgdanamon210058 BCA KE SM,102.01.01,,"FAKTA JAYA-(SMD); BANK BCA 1312",23600003.76,0.00,IDR,1,23600003.76,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertCount(1, $vouchers);
        $this->assertSame('needs_review', $vouchers[0]['status']);

        $entry = ReceiptEntry::query()->where('reference_number', 'OR/KE/07269/08/2026')->firstOrFail();
        $this->assertSame('submitted', $entry->status->value);
        $this->assertEquals(23600003.76, (float) $entry->total_amount);
        $this->assertSame('bank_transfer', $entry->payment_method->value);
        $this->assertCount(0, $entry->items);
    }

    public function test_blank_secondary_reference_infers_cash_payment_method(): void
    {
        Customer::query()->create(['customer_code' => 'C-0100', 'customer_name' => 'CV. Sinar Abadi']);

        $csv = self::PREAMBLE.self::HEADER
            .'02/08/2026,OR/KE/00002/08/2026,,,112.01.01,C-0100,"PIUTANG USAHA, CV. Sinar Abadi, LUNAS",0.00,500000.00,IDR,1,0,500000,Approved'."\r\n"
            .'02/08/2026,OR/KE/00002/08/2026,,,102.01.01,,"CV. Sinar Abadi",500000.00,0.00,IDR,1,500000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $entry = ReceiptEntry::query()->where('reference_number', 'OR/KE/00002/08/2026')->firstOrFail();
        $this->assertSame('cash', $entry->payment_method->value);
    }

    public function test_no_customer_match_fails_that_voucher_only(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'03/08/2026,OR/KE/00003/08/2026,,,112.01.01,C-9999,"PIUTANG USAHA, Zzz Totally Unknown Entity Xyz, NOTE",0.00,100000.00,IDR,1,0,100000,Approved'."\r\n"
            .'03/08/2026,OR/KE/00003/08/2026,,,102.01.01,,"Zzz Totally Unknown Entity Xyz",100000.00,0.00,IDR,1,100000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertSame('failed', $vouchers[0]['status']);
        $this->assertStringContainsString('Customer tidak ditemukan', $vouchers[0]['reason']);
        $this->assertSame(0, ReceiptEntry::query()->count());
    }

    public function test_group_spanning_two_different_customers_fails(): void
    {
        Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Toko Alpha']);
        Customer::query()->create(['customer_code' => 'C-0002', 'customer_name' => 'Toko Beta']);

        $csv = self::PREAMBLE.self::HEADER
            .'04/08/2026,OR/KE/00004/08/2026,,,112.01.01,C-0001,"PIUTANG USAHA, Toko Alpha, NOTE",0.00,60000.00,IDR,1,0,60000,Approved'."\r\n"
            .'04/08/2026,OR/KE/00004/08/2026,,,112.01.01,C-0002,"PIUTANG USAHA, Toko Beta, NOTE",0.00,40000.00,IDR,1,0,40000,Approved'."\r\n"
            .'04/08/2026,OR/KE/00004/08/2026,,,102.01.01,,"Gabungan",100000.00,0.00,IDR,1,100000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertSame('failed', $vouchers[0]['status']);
        $this->assertStringContainsString('lebih dari satu customer', $vouchers[0]['reason']);
    }

    public function test_unbalanced_group_fails_without_blocking_the_rest(): void
    {
        Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Toko Alpha']);

        $csv = self::PREAMBLE.self::HEADER
            .'05/08/2026,OR/KE/00005/08/2026,,,112.01.01,C-0001,"PIUTANG USAHA, Toko Alpha, NOTE",0.00,100000.00,IDR,1,0,100000,Approved'."\r\n"
            .'05/08/2026,OR/KE/00005/08/2026,,,102.01.01,,"Toko Alpha",90000.00,0.00,IDR,1,90000,0,Approved'."\r\n"
            .'06/08/2026,OR/KE/00006/08/2026,,,112.01.01,C-0001,"PIUTANG USAHA, Toko Alpha, NOTE",0.00,50000.00,IDR,1,0,50000,Approved'."\r\n"
            .'06/08/2026,OR/KE/00006/08/2026,,,102.01.01,,"Toko Alpha",50000.00,0.00,IDR,1,50000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = collect($batch->preview_summary['vouchers'])->keyBy('document_number');
        $this->assertSame('failed', $vouchers['OR/KE/00005/08/2026']['status']);
        $this->assertSame('needs_review', $vouchers['OR/KE/00006/08/2026']['status']);
        $this->assertSame(1, $batch->failed_rows);
    }

    public function test_duplicate_reference_number_is_skipped_not_reimported(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Toko Alpha']);

        ReceiptEntry::query()->create([
            'customer_id' => $customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
            'reference_number' => 'OR/KE/00007/08/2026',
            'total_amount' => 75000,
            'payment_method' => 'cash',
        ]);

        $csv = self::PREAMBLE.self::HEADER
            .'07/08/2026,OR/KE/00007/08/2026,,,112.01.01,C-0001,"PIUTANG USAHA, Toko Alpha, NOTE",0.00,75000.00,IDR,1,0,75000,Approved'."\r\n"
            .'07/08/2026,OR/KE/00007/08/2026,,,102.01.01,,"Toko Alpha",75000.00,0.00,IDR,1,75000,0,Approved'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $vouchers = $batch->preview_summary['vouchers'];
        $this->assertSame('needs_review', $vouchers[0]['status']);
        $this->assertStringContainsString('sudah pernah diimpor', $vouchers[0]['reason']);
        $this->assertSame(1, ReceiptEntry::query()->where('reference_number', 'OR/KE/00007/08/2026')->count());
    }
}
