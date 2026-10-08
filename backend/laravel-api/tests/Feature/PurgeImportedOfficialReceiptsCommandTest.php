<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\AccountsReceivable;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\ReceiptEntry;
use App\Services\Import\OfficialReceiptImportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Exercises the Official Receipt import rollback — see PurgeImportedOfficialReceiptsCommand's own docblock. */
class PurgeImportedOfficialReceiptsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected OfficialReceiptImportService $service;

    protected Customer $customer;

    protected Invoice $invoice;

    protected AccountsReceivable $ar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->service = app(OfficialReceiptImportService::class);

        $this->customer = Customer::query()->create(['customer_code' => 'C-0100', 'customer_name' => 'CV. Sinar Abadi']);

        $this->invoice = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_type' => 'goods',
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'subtotal' => 500000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 500000,
        ]);

        $this->ar = AccountsReceivable::query()->create([
            'customer_id' => $this->customer->id, 'invoice_id' => $this->invoice->id,
            'reference_number' => $this->invoice->document_number ?? 'INV-TEST', 'amount' => 500000, 'paid_amount' => 0,
            'due_date' => '2026-08-31', 'status' => 'unpaid',
        ]);
    }

    private const PREAMBLE = "Official Receipt Listing\r\nPeriod: 01/08/2026 - 31/08/2026\r\nPT Test Company - Printed 01/09/2026\r\n\r\n";

    private const HEADER = "DATE,DOCUMENT #,COLLECTION DATE,CHEQUE #,ACCOUNT,SL CODE,PARTICULARS,DEBIT,CREDIT,CUR.,RATE,C.DEBIT,C.CREDIT,STATUS\r\n";

    private function importAllocatedReceipt(string $documentNumber): ImportBatch
    {
        $csv = self::PREAMBLE.self::HEADER
            ."02/08/2026,{$documentNumber},,,112.01.01,C-0100,\"PIUTANG USAHA, CV. Sinar Abadi, LUNAS\",0.00,500000.00,IDR,1,0,500000,Approved\r\n"
            ."02/08/2026,{$documentNumber},,,102.01.01,,\"CV. Sinar Abadi\",500000.00,0.00,IDR,1,500000,0,Approved\r\n";

        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);
        $batch = ImportBatch::query()->create([
            'module' => 'official-receipts', 'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv', 'disk' => 'local', 'file_path' => $path,
        ]);

        $this->service->import($batch);

        return $batch;
    }

    public function test_commit_reverses_the_allocation_and_deletes_the_receipt_and_batch(): void
    {
        $this->importAllocatedReceipt('OR/KE/00010/08/2026');

        $entry = ReceiptEntry::query()->where('reference_number', 'OR/KE/00010/08/2026')->firstOrFail();
        $this->assertCount(1, $entry->items, 'import must have allocated against the open AR');
        $this->ar->refresh();
        $this->assertEquals(500000, (float) $this->ar->paid_amount);

        $this->artisan('official-receipt-import:purge', ['--commit' => true])->assertExitCode(0);

        $this->assertNull(ReceiptEntry::query()->find($entry->id));
        $this->assertSame(0, PaymentAllocation::query()->where('receipt_entry_id', $entry->id)->count());
        $this->ar->refresh();
        $this->assertEquals(0, (float) $this->ar->paid_amount, 'the AR must be unsettled back to its pre-receipt balance');
        $this->assertSame('unpaid', $this->ar->status->value);
        $this->assertSame(0, ImportBatch::query()->where('module', 'official-receipts')->count());
        $this->assertNotNull(Invoice::query()->find($this->invoice->id), 'the underlying invoice must never be touched');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->importAllocatedReceipt('OR/KE/00010/08/2026');
        $entry = ReceiptEntry::query()->where('reference_number', 'OR/KE/00010/08/2026')->firstOrFail();

        $this->artisan('official-receipt-import:purge')->assertExitCode(0);

        $this->assertNotNull(ReceiptEntry::query()->find($entry->id));
        $this->ar->refresh();
        $this->assertEquals(500000, (float) $this->ar->paid_amount);
        $this->assertSame(1, ImportBatch::query()->where('module', 'official-receipts')->count());
    }

    public function test_leaves_manually_created_receipts_alone(): void
    {
        $manual = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-08-05',
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
            'reference_number' => 'OR/KE/MANUAL/08/2026',
            'total_amount' => 100000,
            'payment_method' => 'cash',
        ]);

        $this->artisan('official-receipt-import:purge', ['--commit' => true])->assertExitCode(0);

        $this->assertNotNull(ReceiptEntry::query()->find($manual->id));
    }
}
