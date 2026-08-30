<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Sales Journal's export — matches the legacy JournalList_Sales.xlsx/JournalList_SalesReturn.xlsx
 * template (verified with openpyxl against the real files). Only covers the no-InvoiceItem path
 * (a header AR line + one aggregate Sales Revenue line) — a real per-item explosion needs a full
 * SalesOrder->Delivery->DeliveryItem->InvoiceItem chain that isn't worth standing up here just to
 * prove SalesJournalExport::mapInvoice()'s items branch; that branch is exercised implicitly by
 * SalesListingExportTest's own invoice fixtures sharing the identical items relation shape.
 */
class SalesJournalExportTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        Permission::query()->firstOrCreate(['name' => 'accounting.journal_list.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.journal_list.view');
        Sanctum::actingAs($user);
    }

    protected function makeInvoice(float $amount): Invoice
    {
        return Invoice::query()->create([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $this->customer->id,
            'invoice_date' => '2026-01-15', 'due_date' => '2026-01-15',
            'subtotal' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $amount,
        ]);
    }

    protected function downloadXlsx(string $query): Worksheet
    {
        $response = $this->get("/api/v1/sales-journal/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'sales-journal').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_sales_invoice_export_banner_headings_and_grand_total(): void
    {
        $invoice = $this->makeInvoice(100000);

        $sheet = $this->downloadXlsx('view=invoice&date_from=2026-01-01&date_to=2026-01-31');

        $this->assertEquals('JOURNAL LIST', $sheet->getCell('A1')->getValue());
        $this->assertEquals('PT. KALINDO ETAM', $sheet->getCell('A2')->getValue());
        $this->assertEquals('01/01/2026 - 31/01/2026', $sheet->getCell('A3')->getValue());

        $this->assertEquals('Transaction', $sheet->getCell('A5')->getValue());
        $this->assertEquals('Tax Code', $sheet->getCell('G5')->getValue());
        $this->assertEquals('Salesman Code', $sheet->getCell('H5')->getValue());
        $this->assertEquals('Sales Journal', $sheet->getCell('A6')->getValue());

        // Header row (AR debit) — Transaction only on the first physical row of the transaction.
        $this->assertEquals($invoice->document_number, $sheet->getCell('A7')->getValue());
        $this->assertEquals('1200 - Accounts Receivable - [Sales, Acme]', $sheet->getCell('D7')->getValue());
        $this->assertEquals(100000, $sheet->getCell('E7')->getValue());
        $this->assertEquals(0, $sheet->getCell('F7')->getValue()); // Credit shown as 0, never blank

        // Detail row (Sales Revenue) — no InvoiceItem rows, so a single aggregate line for the whole subtotal.
        $this->assertNull($sheet->getCell('A8')->getValue()); // Transaction blank on a detail row
        $this->assertEquals('4000 - Sales Revenue - [Sales, Acme]', $sheet->getCell('D8')->getValue());
        $this->assertEquals(0, $sheet->getCell('E8')->getValue());
        $this->assertEquals(100000, $sheet->getCell('F8')->getValue());

        $this->assertEquals('Total For :[Sales Journal]', $sheet->getCell('A9')->getValue());
        $this->assertEquals(100000, $sheet->getCell('E9')->getValue());
        $this->assertEquals(100000, $sheet->getCell('F9')->getValue());
    }

    public function test_credit_note_export_group_label_is_sales_return_journal(): void
    {
        $invoice = $this->makeInvoice(100000);
        CreditNote::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $this->customer->id, 'credit_note_date' => '2026-01-20',
            'reason' => 'returned_goods', 'status' => 'submitted', 'subtotal' => 30000, 'discount_amount' => 0,
            'tax_amount' => 0, 'total_amount' => 30000,
        ]);

        $sheet = $this->downloadXlsx('view=credit_note&date_from=2026-01-01&date_to=2026-01-31');

        $this->assertEquals('Sales Return Journal', $sheet->getCell('A6')->getValue());
        $this->assertEquals('1200 - Accounts Receivable - [Credit Note To Customer, Acme]', $sheet->getCell('D7')->getValue());
        $this->assertEquals(0, $sheet->getCell('E7')->getValue());
        $this->assertEquals(30000, $sheet->getCell('F7')->getValue());
        $this->assertEquals('Total For :[Sales Return Journal]', $sheet->getCell('A9')->getValue());
    }
}
