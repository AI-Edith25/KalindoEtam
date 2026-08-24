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

class ProductSalesExportTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected ItemGroup $itemGroup;

    protected UnitOfMeasurement $uom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->itemGroup = ItemGroup::query()->create(['name' => 'Hardware']);
        $this->uom = UnitOfMeasurement::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    protected function invoicedItem(string $code, string $name, int $qty, float $rate): void
    {
        $item = Item::query()->create([
            'item_code' => $code, 'item_name' => $name, 'item_group_id' => $this->itemGroup->id,
            'uom_id' => $this->uom->id, 'standard_rate' => $rate,
        ]);
        $amount = $qty * $rate;
        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $this->customer->id,
            'invoice_date' => '2026-01-15', 'due_date' => '2026-01-15', 'subtotal' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $amount,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id, 'item_id' => $item->id, 'item_code' => $code,
            'item_name' => $name, 'uom' => 'PCS', 'rate' => $rate, 'qty' => $qty, 'amount' => $amount, 'tax_amount' => 0,
        ]);
    }

    protected function downloadXlsx(string $query): Worksheet
    {
        $response = $this->get("/api/v1/reports/sales/products/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'product-sales').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_xlsx_banner_headings_and_grand_total(): void
    {
        $this->invoicedItem('ITM-1', 'Widget', 10, 1000);

        $sheet = $this->downloadXlsx('date_from=2026-01-01&date_to=2026-01-31');

        $this->assertEquals('PRODUCT SALES REPORT', $sheet->getCell('A1')->getValue());
        $this->assertEquals('PT. KALINDO ETAM', $sheet->getCell('A2')->getValue());
        $this->assertEquals('01/01/2026 - 31/01/2026', $sheet->getCell('A3')->getValue());
        $this->assertNotNull($sheet->getCell('D3')->getValue());

        $this->assertEquals('ITEM CODE', $sheet->getCell('A5')->getValue());
        $this->assertEquals('ITM-1', $sheet->getCell('A6')->getValue());
        $this->assertEquals('Widget', $sheet->getCell('B6')->getValue());
        $this->assertEquals('Hardware', $sheet->getCell('C6')->getValue());
        $this->assertEquals(10, $sheet->getCell('E6')->getValue());
        $this->assertEquals(10000, $sheet->getCell('F6')->getValue());
        $this->assertEquals(0, $sheet->getCell('G6')->getValue()); // DISC/ADJUSTMENT — genuinely 0, must not render blank

        $this->assertEquals('Grand Total', $sheet->getCell('A7')->getValue());
        $this->assertEquals(10000, $sheet->getCell('F7')->getValue());
    }

    public function test_xlsx_percent_of_revenue_sums_to_100(): void
    {
        $this->invoicedItem('ITM-1', 'Widget A', 10, 1000); // 10000
        $this->invoicedItem('ITM-2', 'Widget B', 5, 2000); // 10000

        $sheet = $this->downloadXlsx('date_from=2026-01-01&date_to=2026-01-31&sort=item_name&sort_dir=asc');

        $this->assertEquals(50.0, $sheet->getCell('J6')->getValue());
        $this->assertEquals(50.0, $sheet->getCell('J7')->getValue());
        $this->assertEquals(100.0, $sheet->getCell('J8')->getValue());
    }

    public function test_csv_has_no_banner_just_headings_and_raw_data(): void
    {
        $this->invoicedItem('ITM-1', 'Widget', 10, 1000);

        $response = $this->get('/api/v1/reports/sales/products/export?format=csv');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringNotContainsString('PRODUCT SALES REPORT', $content);
        $this->assertStringNotContainsString('PT. KALINDO ETAM', $content);
        $this->assertStringContainsString('ITEM CODE', $content);
        $this->assertStringContainsString('ITM-1', $content);
        $this->assertStringContainsString('10000', $content); // raw number, no thousands separator
        $this->assertStringNotContainsString('10,000', $content);
    }

    public function test_download_filename_follows_reportname_daterange_pattern(): void
    {
        $this->invoicedItem('ITM-1', 'Widget', 10, 1000);

        $this->get('/api/v1/reports/sales/products/export?date_from=2026-07-24&date_to=2026-08-24')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=ProductSalesReport_20260724-20260824_' . now()->format('Hi') . '.xlsx');
    }
}
