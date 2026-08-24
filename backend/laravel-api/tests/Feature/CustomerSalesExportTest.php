<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class CustomerSalesExportTest extends TestCase
{
    use RefreshDatabase;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $itemGroup = ItemGroup::query()->create(['name' => 'Hardware']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000]);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    protected function invoicedCustomer(string $code, string $name, int $qty, float $rate): Customer
    {
        $customer = Customer::query()->create(['customer_code' => $code, 'customer_name' => $name]);
        $amount = $qty * $rate;
        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $customer->id,
            'invoice_date' => '2026-01-15', 'due_date' => '2026-01-15', 'subtotal' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $amount,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id, 'item_id' => $this->item->id, 'item_code' => $this->item->item_code,
            'item_name' => $this->item->item_name, 'uom' => 'PCS', 'rate' => $rate, 'qty' => $qty, 'amount' => $amount, 'tax_amount' => 0,
        ]);

        return $customer;
    }

    protected function downloadXlsx(string $query): Worksheet
    {
        $response = $this->get("/api/v1/reports/sales/customers/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'customer-sales').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_xlsx_banner_headings_and_grand_total(): void
    {
        $this->invoicedCustomer('C001', 'Acme', 10, 1000);

        $sheet = $this->downloadXlsx('date_from=2026-01-01&date_to=2026-01-31');

        $this->assertEquals('CUSTOMER SALES REPORT', $sheet->getCell('A1')->getValue());
        $this->assertEquals('PT. KALINDO ETAM', $sheet->getCell('A2')->getValue());
        $this->assertEquals('01/01/2026 - 31/01/2026', $sheet->getCell('A3')->getValue());

        $this->assertEquals('CUSTOMER CODE', $sheet->getCell('A5')->getValue());
        $this->assertEquals('C001', $sheet->getCell('A6')->getValue());
        $this->assertEquals('Acme', $sheet->getCell('B6')->getValue());
        $this->assertEquals(1, $sheet->getCell('E6')->getValue());
        $this->assertEquals(10, $sheet->getCell('F6')->getValue());
        $this->assertEquals(10000, $sheet->getCell('G6')->getValue());

        $this->assertEquals('Grand Total', $sheet->getCell('A7')->getValue());
        $this->assertEquals(10000, $sheet->getCell('G7')->getValue());
    }

    public function test_csv_has_no_banner_just_headings_and_raw_data(): void
    {
        $this->invoicedCustomer('C001', 'Acme', 10, 1000);

        $response = $this->get('/api/v1/reports/sales/customers/export?format=csv');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringNotContainsString('CUSTOMER SALES REPORT', $content);
        $this->assertStringContainsString('CUSTOMER CODE', $content);
        $this->assertStringContainsString('C001', $content);
    }

    public function test_download_filename_follows_reportname_daterange_pattern(): void
    {
        $this->invoicedCustomer('C001', 'Acme', 10, 1000);

        $this->get('/api/v1/reports/sales/customers/export?date_from=2026-07-24&date_to=2026-08-24')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=CustomerSalesReport_20260724-20260824_' . now()->format('Hi') . '.xlsx');
    }
}
