<?php

namespace Tests\Feature;

use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Import\CustomerOutstandingArchiveImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises the 2026-10-08 addition to CustomerOutstandingArchiveImportService: a real
 * Invoice + AccountsReceivable per line, alongside the (unchanged) archive snapshot.
 */
class CustomerOutstandingArchiveSiCreationTest extends TestCase
{
    use RefreshDatabase;

    protected CustomerOutstandingArchiveImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->service = app(CustomerOutstandingArchiveImportService::class);
    }

    private function csv(array $rows): string
    {
        return implode("\r\n", array_map(
            fn (array $row) => implode(',', array_map(fn ($v) => $v ?? '', $row)),
            $rows
        ))."\r\n";
    }

    private const HEADER = ['Date', 'Ref. No', 'Invoice Amt', 'Paid Amount', 'Unpaid Amount', 'Terms (Days)', 'Due Date', 'Overdue Amount', 'Overdue (Days)'];

    private function writeFixture(string $content): string
    {
        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $content);

        return Storage::disk('local')->path($path);
    }

    public function test_commit_creates_an_invoice_and_ar_seeded_from_the_file_with_gl_and_stock_skipped(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C-0100', 'customer_name' => 'CV Sinar Abadi']);

        $csv = $this->csv([
            ['Customer Unpaid Bills With Overdue Advice'],
            ['PT Test Company'],
            ['Date as at : 30/09/2026'],
            [''],
            self::HEADER,
            ['Customer : C-0100 - CV Sinar Abadi'],
            ['01/09/2026', 'SI/KE/00001/09/2026', 500000, 100000, 400000, 30, '01/10/2026', 0, 0],
            ['', '', '', '', 400000, '', '', 0, ''],
            ['Grand Total', '', '', '', 400000, '', '', 0, ''],
            ['Printed By : Admin'],
        ]);

        $path = $this->writeFixture($csv);
        ['snapshot' => $snapshot, 'si_import' => $siImport] = $this->service->commit($path, 'csv', 'test.csv', null);

        $this->assertSame(1, $siImport['created']);
        $this->assertSame([], $siImport['skipped_customer']);
        $this->assertSame([], $siImport['skipped_type']);
        $this->assertSame(1, $snapshot->lines()->count(), 'the archive line must still be written regardless');

        $invoice = Invoice::query()->where('source_document_number', 'SI/KE/00001/09/2026')->firstOrFail();
        $this->assertSame('goods', $invoice->invoice_type->value);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame('submitted', $invoice->status->value);
        $this->assertEquals(500000, (float) $invoice->grand_total);
        $this->assertSame('outstanding_bills_archive', $invoice->import_source_type);
        $this->assertSame('import', $invoice->source);
        $this->assertNull($invoice->warehouse_id, 'must never move stock');

        $ar = $invoice->accountsReceivable;
        $this->assertNotNull($ar);
        $this->assertEquals(500000, (float) $ar->amount);
        $this->assertEquals(100000, (float) $ar->paid_amount, 'seeded from the file\'s own Paid Amount column, not left at 0');
        $this->assertSame('partially_paid', $ar->status->value);

        // No GL posted for this invoice (import_source_type set) -- see InvoiceService::submit().
        $this->assertSame(0, \App\Models\JournalEntry::query()->where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)->count());
    }

    public function test_commit_skips_a_line_whose_customer_code_does_not_match_any_live_customer(): void
    {
        $csv = $this->csv([
            ['Customer Unpaid Bills With Overdue Advice'],
            ['PT Test Company'],
            ['Date as at : 30/09/2026'],
            [''],
            self::HEADER,
            ['Customer : C-9999 - Unknown Co'],
            ['01/09/2026', 'SI/KE/00002/09/2026', 100000, 0, 100000, 30, '01/10/2026', 0, 0],
            ['', '', '', '', 100000, '', '', 0, ''],
            ['Grand Total', '', '', '', 100000, '', '', 0, ''],
            ['Printed By : Admin'],
        ]);

        ['si_import' => $siImport] = $this->service->commit($this->writeFixture($csv), 'csv', 'test.csv', null);

        $this->assertSame(0, $siImport['created']);
        $this->assertSame(['SI/KE/00002/09/2026'], $siImport['skipped_customer']);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_commit_skips_a_ref_no_with_an_unrecognized_prefix(): void
    {
        Customer::query()->create(['customer_code' => 'C-0100', 'customer_name' => 'CV Sinar Abadi']);

        $csv = $this->csv([
            ['Customer Unpaid Bills With Overdue Advice'],
            ['PT Test Company'],
            ['Date as at : 30/09/2026'],
            [''],
            self::HEADER,
            ['Customer : C-0100 - CV Sinar Abadi'],
            ['01/09/2026', 'CN/KE/00001/09/2026', 100000, 0, 100000, 30, '01/10/2026', 0, 0],
            ['', '', '', '', 100000, '', '', 0, ''],
            ['Grand Total', '', '', '', 100000, '', '', 0, ''],
            ['Printed By : Admin'],
        ]);

        ['si_import' => $siImport] = $this->service->commit($this->writeFixture($csv), 'csv', 'test.csv', null);

        $this->assertSame(0, $siImport['created']);
        $this->assertSame(['CN/KE/00001/09/2026'], $siImport['skipped_type']);
    }

    public function test_commit_skips_a_ref_no_that_already_belongs_to_a_live_invoice(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C-0100', 'customer_name' => 'CV Sinar Abadi']);
        Invoice::query()->create([
            'customer_id' => $customer->id,
            'document_number' => 'SI/KE/EXISTING/09/2026',
            'invoice_type' => 'goods',
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'subtotal' => 100000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 100000,
            'source_document_number' => 'SI/KE/00001/09/2026',
        ]);

        $csv = $this->csv([
            ['Customer Unpaid Bills With Overdue Advice'],
            ['PT Test Company'],
            ['Date as at : 30/09/2026'],
            [''],
            self::HEADER,
            ['Customer : C-0100 - CV Sinar Abadi'],
            ['01/09/2026', 'SI/KE/00001/09/2026', 100000, 0, 100000, 30, '01/10/2026', 0, 0],
            ['', '', '', '', 100000, '', '', 0, ''],
            ['Grand Total', '', '', '', 100000, '', '', 0, ''],
            ['Printed By : Admin'],
        ]);

        ['si_import' => $siImport] = $this->service->commit($this->writeFixture($csv), 'csv', 'test.csv', null);

        $this->assertSame(0, $siImport['created']);
        $this->assertSame(['SI/KE/00001/09/2026'], $siImport['skipped_duplicate']);
        $this->assertSame(1, Invoice::query()->count(), 'only the pre-existing one, nothing new created');
    }

    public function test_preflight_reports_the_same_si_classification_without_writing_anything(): void
    {
        Customer::query()->create(['customer_code' => 'C-0100', 'customer_name' => 'CV Sinar Abadi']);

        $csv = $this->csv([
            ['Customer Unpaid Bills With Overdue Advice'],
            ['PT Test Company'],
            ['Date as at : 30/09/2026'],
            [''],
            self::HEADER,
            ['Customer : C-0100 - CV Sinar Abadi'],
            ['01/09/2026', 'SI/KE/00001/09/2026', 100000, 0, 100000, 30, '01/10/2026', 0, 0],
            ['', '', '', '', 100000, '', '', 0, ''],
            ['Grand Total', '', '', '', 100000, '', '', 0, ''],
            ['Printed By : Admin'],
        ]);

        $preview = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(1, $preview['si_preview']['will_create']);
        $this->assertSame(0, Invoice::query()->count(), 'preflight must never write');
        $this->assertSame(0, AccountsReceivable::query()->count());
    }

    public function test_preflight_skipped_rows_carry_full_row_detail_not_just_ref_no(): void
    {
        // No Customer master row exists for C-0100 at all -- skip_customer. C-0200 exists, so its
        // row clears the customer check; CN/KE prefix is neither SI/KE nor TR/KE -- skip_type.
        Customer::query()->create(['customer_code' => 'C-0200', 'customer_name' => 'Toko Jaya']);

        $csv = $this->csv([
            ['Customer Unpaid Bills With Overdue Advice'],
            ['PT Test Company'],
            ['Date as at : 30/09/2026'],
            [''],
            self::HEADER,
            ['Customer : C-0100 - CV Sinar Abadi'],
            ['01/09/2026', 'SI/KE/00001/09/2026', 100000, 0, 100000, 30, '01/10/2026', 0, 0],
            ['Customer : C-0200 - Toko Jaya'],
            ['02/09/2026', 'CN/KE/00002/09/2026', 50000, 0, 50000, 30, '02/10/2026', 0, 0],
            ['', '', '', '', 150000, '', '', 0, ''],
            ['Grand Total', '', '', '', 150000, '', '', 0, ''],
            ['Printed By : Admin'],
        ]);

        $preview = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertCount(1, $preview['si_preview']['skipped_customer']);
        $this->assertSame([
            'customer_code' => 'C-0100',
            'customer_name' => 'CV Sinar Abadi',
            'ref_no' => 'SI/KE/00001/09/2026',
            'invoice_amount' => 100000.0,
            'due_date' => '2026-10-01',
        ], $preview['si_preview']['skipped_customer'][0]);

        $this->assertCount(1, $preview['si_preview']['skipped_type']);
        $this->assertSame('CN/KE/00002/09/2026', $preview['si_preview']['skipped_type'][0]['ref_no']);
        $this->assertSame('C-0200', $preview['si_preview']['skipped_type'][0]['customer_code']);
    }
}
