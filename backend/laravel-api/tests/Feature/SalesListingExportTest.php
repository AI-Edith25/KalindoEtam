<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class SalesListingExportTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    protected function makeInvoice(float $amount): Invoice
    {
        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $this->customer->id,
            'invoice_date' => '2026-01-15', 'due_date' => '2026-01-15', 'subtotal' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $amount,
        ]);
        AccountsReceivable::query()->create([
            'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'reference_number' => $invoice->document_number,
            'amount' => $amount, 'paid_amount' => 0, 'due_date' => now()->addDays(30)->toDateString(), 'status' => AccountsReceivableStatus::UNPAID,
        ]);

        return $invoice;
    }

    protected function downloadXlsx(string $query): Worksheet
    {
        $response = $this->get("/api/v1/reports/sales/listing/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'sales-listing').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_xlsx_banner_headings_and_grand_total(): void
    {
        $invoice = $this->makeInvoice(100000);

        $sheet = $this->downloadXlsx('date_from=2026-01-01&date_to=2026-01-31');

        $this->assertEquals('SALES LISTING REPORT', $sheet->getCell('A1')->getValue());
        $this->assertEquals('PT. KALINDO ETAM', $sheet->getCell('A2')->getValue());
        $this->assertEquals('01/01/2026 - 31/01/2026', $sheet->getCell('A3')->getValue());

        $this->assertEquals('DATE', $sheet->getCell('A5')->getValue());
        $this->assertEquals($invoice->document_number, $sheet->getCell('B6')->getValue());
        $this->assertEquals('Acme', $sheet->getCell('F6')->getValue());
        $this->assertEquals('Sales Invoice', $sheet->getCell('G6')->getValue());
        $this->assertEquals(100000, $sheet->getCell('H6')->getValue());

        $this->assertEquals('Grand Total', $sheet->getCell('A7')->getValue());
        $this->assertEquals(100000, $sheet->getCell('H7')->getValue());
    }

    public function test_csv_has_no_banner_just_headings_and_raw_data(): void
    {
        $this->makeInvoice(100000);

        $response = $this->get('/api/v1/reports/sales/listing/export?format=csv');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringNotContainsString('SALES LISTING REPORT', $content);
        $this->assertStringContainsString('DATE', $content);
    }

    public function test_download_filename_follows_reportname_daterange_pattern(): void
    {
        $this->makeInvoice(100000);

        $this->get('/api/v1/reports/sales/listing/export?date_from=2026-07-24&date_to=2026-08-24')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=SalesListingReport_20260724-20260824_' . now()->format('Hi') . '.xlsx');
    }
}
