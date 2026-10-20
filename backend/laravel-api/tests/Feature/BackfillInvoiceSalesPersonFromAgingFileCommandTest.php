<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Reproduces the real legacy file's structure (title/date/company preamble, column headers, a
 * "Sales Person : <code> - [<name>]" group header, "Customer : <code> - <name>, Sales Person :
 * <name>" lines, document data rows, a per-customer subtotal row) at a small scale, rather than
 * shipping the real multi-hundred-KB reference file into the test suite.
 */
class BackfillInvoiceSalesPersonFromAgingFileCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $filePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filePath = tempnam(sys_get_temp_dir(), 'aging-fixture') . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->filePath)) {
            unlink($this->filePath);
        }

        parent::tearDown();
    }

    /** @param array<int, array<int, mixed>> $rows */
    protected function writeFixture(array $rows): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');
        (new Xlsx($spreadsheet))->save($this->filePath);
    }

    protected function makeInvoice(string $documentNumber, Customer $customer, ?string $salesPersonId = null): Invoice
    {
        return Invoice::query()->create([
            'document_number' => $documentNumber,
            'invoice_type' => 'goods',
            'status' => 'submitted',
            'customer_id' => $customer->id,
            'sales_person_id' => $salesPersonId,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 100000,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => 100000,
        ]);
    }

    public function test_backfills_only_invoices_with_no_sales_person_assigned_yet(): void
    {
        $benny = SalesPerson::query()->create(['code' => 'KE-BENNY', 'name' => 'BENNY WIJAYA H']);
        $karlina = SalesPerson::query()->create(['code' => 'KE-KARLINA', 'name' => 'KARLINA']);
        $customer = Customer::query()->create(['customer_code' => 'C-0043', 'customer_name' => 'ANDREW WICAKSANA_BPP']);
        $alreadyAssigned = SalesPerson::query()->create(['code' => 'KE-OTHER', 'name' => 'OTHER PERSON']);

        $blank = $this->makeInvoice('SI/KE/08766/09/2026', $customer);
        $untouched = $this->makeInvoice('SI/KE/09003/10/2026', $customer, $alreadyAssigned->id);

        $this->writeFixture([
            ['Customers Detail Aging - By Sales Person, Customer'],
            ['Filter By Date : as at 31/10/2026'],
            ['PT. KALINDO ETAM'],
            [null],
            ['Document No.', 'Date', 'Current', '1 Month'],
            [null],
            ['Sales Person : KE-BENNY - [BENNY WIJAYA H]'],
            ['Customer : C-0043 - ANDREW WICAKSANA_BPP, Sales Person : BENNY WIJAYA H', null, null, null, 'Ctn: , , Tel : , Fax : , Terms : 14 days'],
            ['SI/KE/08766/09/2026', '30/09/2026', 0, 555099973.04],
            [null, null, 0, 555099973.04],
            [null],
            ['Customer : C-0043 - ANDREW WICAKSANA_BPP, Sales Person : KARLINA', null, null, null, 'Ctn: , , Tel : , Fax : , Terms : 14 days'],
            ['SI/KE/09003/10/2026', '09/10/2026', 7400001.48, 0],
            [null, null, 7400001.48, 0],
        ]);

        $this->artisan('invoices:backfill-sales-person-from-aging-file', ['path' => $this->filePath])->assertExitCode(0);

        $this->assertEquals($benny->id, $blank->refresh()->sales_person_id);
        // Already had a Sales Person (OTHER PERSON) — never overwritten even though the file says KARLINA.
        $this->assertEquals($alreadyAssigned->id, $untouched->refresh()->sales_person_id);
        $this->assertNotEquals($karlina->id, $untouched->sales_person_id);
    }

    public function test_dry_run_reports_without_saving(): void
    {
        $benny = SalesPerson::query()->create(['code' => 'KE-BENNY', 'name' => 'BENNY WIJAYA H']);
        $customer = Customer::query()->create(['customer_code' => 'C-0043', 'customer_name' => 'ANDREW WICAKSANA_BPP']);
        $invoice = $this->makeInvoice('SI/KE/08766/09/2026', $customer);

        $this->writeFixture([
            ['Customers Detail Aging - By Sales Person, Customer'],
            [null],
            ['Sales Person : KE-BENNY - [BENNY WIJAYA H]'],
            ['Customer : C-0043 - ANDREW WICAKSANA_BPP, Sales Person : BENNY WIJAYA H'],
            ['SI/KE/08766/09/2026', '30/09/2026', 0, 0],
        ]);

        $this->artisan('invoices:backfill-sales-person-from-aging-file', ['path' => $this->filePath, '--dry-run' => true])->assertExitCode(0);

        $this->assertNull($invoice->refresh()->sales_person_id);
    }

    public function test_unknown_sales_person_name_and_unmatched_document_are_skipped_without_error(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C-9999', 'customer_name' => 'GHOST CUSTOMER']);

        $this->writeFixture([
            ['Sales Person : KE-NOBODY - [NOBODY REAL]'],
            ['Customer : C-9999 - GHOST CUSTOMER, Sales Person : NOBODY REAL'],
            ['SI/KE/NOT-IN-DB/10/2026', '01/10/2026', 0, 0],
        ]);

        $this->artisan('invoices:backfill-sales-person-from-aging-file', ['path' => $this->filePath])->assertExitCode(0);
    }
}
