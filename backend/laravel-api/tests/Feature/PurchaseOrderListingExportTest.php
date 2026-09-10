<?php

namespace Tests\Feature;

use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Services\PurchaseOrderReportService;
use App\Services\PurchaseOrderService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PurchaseOrderListingExportTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected PurchaseOrderReportService $purchaseOrderReportService;
    protected Supplier $supplier;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->purchaseOrderReportService = app(PurchaseOrderReportService::class);

        Company::query()->create(['name' => 'PT. KALINDO ETAM', 'code' => 'KE', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Ton']);
        $this->item = Item::query()->create([
            'item_code' => 'CEM-1', 'item_name' => 'Semen Curah', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 100000,
        ]);
    }

    protected function makeTax(array $overrides = []): Tax
    {
        return Tax::query()->create(array_merge([
            'code' => 'PPN11', 'name' => 'PPN 11%', 'type' => TaxType::VAT,
            'transaction_type' => TaxTransactionType::PURCHASE, 'rate' => 11, 'is_active' => true,
        ], $overrides));
    }

    protected function makePurchaseOrder(int $qty, float $rate, ?string $taxId = null, string $remarks = ''): \App\Models\PurchaseOrder
    {
        return $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'remarks' => $remarks,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate, 'tax_id' => $taxId]],
        ]);
    }

    public function test_summary_rows_use_header_fields_directly_with_disc_always_zero(): void
    {
        $tax = $this->makeTax();
        $po = $this->makePurchaseOrder(qty: 10, rate: 100000, taxId: $tax->id, remarks: 'TUJUAN BALIKPAPAN');

        $orders = $this->purchaseOrderReportService->rows([]);
        $rows = $this->purchaseOrderReportService->summaryRows($orders);

        $this->assertCount(2, $rows); // 1 PO + Total

        $row = $rows[0];
        $this->assertEquals($po->document_number, $row[1]);
        $this->assertEquals($this->supplier->supplier_code, $row[2]);
        $this->assertEquals('RP - 1.00', $row[4]);
        $this->assertEquals(1000000.0, $row[5]); // EXCL.TAX = total_amount, real numeric
        $this->assertSame(0.0, $row[6]); // DISC — always 0
        $this->assertEquals(110000.0, $row[7]); // TAX = tax_amount
        $this->assertEquals(1110000.0, $row[8]); // INCL.TAX = grand_total
        $this->assertEquals('TUJUAN BALIKPAPAN', $row[9]); // NOTES = remarks

        $total = $rows[1];
        $this->assertEquals('Total By Header', $total[4]);
        $this->assertEquals('1,000,000.00', $total[5]);
        $this->assertEquals('1,110,000.00', $total[8]);
    }

    public function test_detail_rows_hardcode_header_disc_and_tax_to_zero_but_keep_real_item_tax(): void
    {
        $tax = $this->makeTax();
        $this->makePurchaseOrder(qty: 10, rate: 100000, taxId: $tax->id);

        $orders = $this->purchaseOrderReportService->rows([]);
        $rows = $this->purchaseOrderReportService->detailRows($orders);

        $this->assertCount(3, $rows); // PO header row, item-label row, 1 item row

        $header = $rows[0];
        $this->assertSame(0.0, $header[7]); // DISC hardcoded 0 — template quirk, preserved deliberately
        $this->assertSame(0.0, $header[8]); // TAX hardcoded 0 — even though the PO has a real tax_amount
        $this->assertEquals(1110000.0, $header[10]); // AMOUNT = grand_total

        $itemLabelRow = $rows[1];
        $this->assertEquals('ITEM #', $itemLabelRow[0]);
        $this->assertEquals('LINE AMOUNT', $itemLabelRow[10]);

        $itemRow = $rows[2];
        $this->assertEquals('CEM-1', $itemRow[0]);
        $this->assertEquals('Semen Curah', $itemRow[2]);
        $this->assertEquals(10.0, $itemRow[5]); // QUANTITY
        $this->assertEquals(110000.0, $itemRow[8]); // TAX — the item row's own tax IS real
        $this->assertEquals('PPN11', $itemRow[9]); // T.CODE
        $this->assertEquals(1000000.0, $itemRow[10]); // LINE AMOUNT
    }

    public function test_export_route_requires_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/purchase-orders/export?mode=summary')->assertForbidden();
    }

    public function test_export_route_downloads_a_real_xlsx_matching_the_reference_template_layout(): void
    {
        $tax = $this->makeTax();
        $this->makePurchaseOrder(qty: 10, rate: 100000, taxId: $tax->id, remarks: 'TUJUAN BALIKPAPAN');

        Permission::query()->firstOrCreate(['name' => 'purchase.orders.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('purchase.orders.view');
        Sanctum::actingAs($viewer);

        $response = $this->get('/api/v1/purchase-orders/export?mode=summary&format=xlsx');
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'po-listing') . '.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();

        $this->assertEquals('PURCHASE ORDER LISTING - SUMMARY', $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString(' - Base Currency', $sheet->getCell('A2')->getValue());
        $this->assertEquals('PT. KALINDO ETAM', $sheet->getCell('A5')->getValue());
        $this->assertNotEmpty($sheet->getCell('D5')->getValue()); // timestamp always column D for PO, verified against the reference file
        $this->assertEquals('DOCUMENT#', $sheet->getCell('B8')->getValue());
        $this->assertEquals('SUPPLIER #', $sheet->getCell('C8')->getValue());
        $this->assertEquals('NOTES', $sheet->getCell('J8')->getValue()); // NOTES, not "REFERENCE 1 #" — see plan Context
        $this->assertEquals('#,##0.00', $sheet->getStyle('F9')->getNumberFormat()->getFormatCode()); // real numeric money cell
        $this->assertEquals(1000000.0, $sheet->getCell('F9')->getValue());
        $this->assertEquals('Total By Header', $sheet->getCell('E10')->getValue());

        unlink($tmpPath);
    }

    public function test_export_route_detail_mode_repeats_item_label_row_per_po(): void
    {
        $this->makePurchaseOrder(qty: 10, rate: 100000);
        $this->makePurchaseOrder(qty: 5, rate: 200000);

        Permission::query()->firstOrCreate(['name' => 'purchase.orders.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('purchase.orders.view');
        Sanctum::actingAs($viewer);

        $response = $this->get('/api/v1/purchase-orders/export?mode=detail&format=xlsx');
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'po-listing-detail') . '.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();

        $this->assertEquals('PURCHASE ORDER LISTING - DETAIL', $sheet->getCell('A1')->getValue());
        $this->assertEquals('DATE', $sheet->getCell('A8')->getValue());
        $this->assertEquals('NOTES', $sheet->getCell('L8')->getValue());
        // Row 9: PO #1 header, row 10: its item-label row, row 11: its 1 item, row 12: PO #2 header, row 13: item-label, row 14: item.
        $this->assertEquals('ITEM #', $sheet->getCell('A10')->getValue());
        $this->assertEquals('ITEM #', $sheet->getCell('A13')->getValue());

        unlink($tmpPath);
    }
}
