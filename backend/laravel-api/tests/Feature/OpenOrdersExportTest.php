<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class OpenOrdersExportTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $itemGroup = ItemGroup::query()->create(['name' => 'Hardware']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000]);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    protected function openLine(int $qty, float $rate): SalesOrderItem
    {
        $salesOrder = SalesOrder::query()->create([
            'customer_id' => $this->customer->id, 'status' => 'approved', 'order_date' => now()->toDateString(),
        ]);

        return SalesOrderItem::query()->create([
            'sales_order_id' => $salesOrder->id, 'item_id' => $this->item->id,
            'qty' => $qty, 'rate' => $rate, 'amount' => $qty * $rate, 'delivered_qty' => 0,
        ]);
    }

    protected function downloadXlsx(string $query): Worksheet
    {
        $response = $this->get("/api/v1/reports/sales/open-orders/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'open-orders').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_xlsx_banner_headings_and_grand_total(): void
    {
        $soItem = $this->openLine(10, 1000);

        $sheet = $this->downloadXlsx('');

        $this->assertEquals('OPEN ORDERS REPORT', $sheet->getCell('A1')->getValue());
        $this->assertEquals('PT. KALINDO ETAM', $sheet->getCell('A2')->getValue());

        $this->assertEquals('SO DATE', $sheet->getCell('A5')->getValue());
        $this->assertEquals($soItem->salesOrder->document_number, $sheet->getCell('B6')->getValue());
        $this->assertEquals('Acme', $sheet->getCell('C6')->getValue());
        $this->assertEquals(10, $sheet->getCell('G6')->getValue());
        $this->assertEquals('Not Delivered', $sheet->getCell('L6')->getValue());
        $this->assertEquals('Not Invoiced', $sheet->getCell('M6')->getValue());

        $this->assertEquals('Grand Total', $sheet->getCell('A7')->getValue());
        $this->assertEquals(10000, $sheet->getCell('K7')->getValue());
    }

    public function test_csv_has_no_banner_just_headings_and_raw_data(): void
    {
        $this->openLine(10, 1000);

        $response = $this->get('/api/v1/reports/sales/open-orders/export?format=csv');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringNotContainsString('OPEN ORDERS REPORT', $content);
        $this->assertStringContainsString('SO DATE', $content);
    }

    public function test_download_filename_follows_reportname_daterange_pattern(): void
    {
        $this->openLine(10, 1000);

        $this->get('/api/v1/reports/sales/open-orders/export?date_from=2026-07-24&date_to=2026-08-24')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=OpenOrdersReport_20260724-20260824_' . now()->format('Hi') . '.xlsx');
    }
}
