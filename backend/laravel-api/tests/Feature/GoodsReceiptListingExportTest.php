<?php

namespace Tests\Feature;

use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Enums\WarehouseType;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GoodsReceiptReportService;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class GoodsReceiptListingExportTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected GoodsReceiptReportService $goodsReceiptReportService;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);
        $this->goodsReceiptReportService = app(GoodsReceiptReportService::class);

        Company::query()->create(['name' => 'PT. KALINDO ETAM', 'code' => 'KE', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
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

    protected function submittedPurchaseOrder(int $qty, float $rate, ?string $taxId = null, string $remarks = ''): \App\Models\PurchaseOrder
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'remarks' => $remarks,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate, 'tax_id' => $taxId]],
        ]);
        $this->approveDocument($purchaseOrder);

        return $this->purchaseOrderService->submit($purchaseOrder);
    }

    public function test_summary_rows_sum_from_items_and_carry_po_reference(): void
    {
        $tax = $this->makeTax();
        $po = $this->submittedPurchaseOrder(qty: 10, rate: 100000, taxId: $tax->id, remarks: 'TUJUAN SAMARINDA');

        $gr = $this->goodsReceiptService->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 10]],
        ]);

        $receipts = $this->goodsReceiptReportService->rows([]);
        $rows = $this->goodsReceiptReportService->summaryRows($receipts);

        $this->assertCount(2, $rows); // 1 GR + Total

        $row = $rows[0];
        $this->assertEquals($gr->document_number, $row[1]);
        $this->assertEquals(1000000.0, $row[5]); // Excl.Tax = sum(item.amount)
        $this->assertSame(0.0, $row[6]); // Disc — always 0
        $this->assertEquals(110000.0, $row[7]); // Tax = sum(item.tax_amount)
        $this->assertEquals(1110000.0, $row[8]); // Incl.Tax
        $this->assertEquals($po->document_number, $row[9]); // Reference 1 # = linked PO number
        $this->assertEquals('TUJUAN SAMARINDA', $row[10]); // Reference 2 # = linked PO's remarks
    }

    public function test_direct_receipt_has_no_po_reference_and_only_manual_tax(): void
    {
        $tax = $this->makeTax();

        $this->goodsReceiptService->create([
            'purchase_order_id' => null,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 4, 'rate' => 100000, 'tax_id' => $tax->id]],
        ]);

        $receipts = $this->goodsReceiptReportService->rows([]);
        $row = $this->goodsReceiptReportService->summaryRows($receipts)[0];

        $this->assertEquals(400000.0, $row[5]);
        $this->assertEquals(44000.0, $row[7]);
        $this->assertNull($row[9]); // no PO — Reference 1 # blank
        $this->assertNull($row[10]); // no PO — Reference 2 # blank
    }

    public function test_detail_rows_carry_location_and_po_no_but_leave_sales_person_blank(): void
    {
        $tax = $this->makeTax();
        $po = $this->submittedPurchaseOrder(qty: 10, rate: 100000, taxId: $tax->id);

        $this->goodsReceiptService->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 10]],
        ]);

        $receipts = $this->goodsReceiptReportService->rows([]);
        $rows = $this->goodsReceiptReportService->detailRows($receipts);

        $this->assertCount(3, $rows); // GR header row, item-label row, 1 item row

        $header = $rows[0];
        $this->assertSame(0.0, $header[7]); // Disc always 0
        $this->assertEquals(110000.0, $header[8]); // Tax — unlike PO Detail, this IS the real aggregate
        $this->assertEquals(1110000.0, $header[9]); // Amount

        $itemRow = $rows[2];
        $this->assertEquals('CEM-1', $itemRow[0]);
        $this->assertEquals(110000.0, $itemRow[8]); // item's own Tax
        $this->assertEquals($po->document_number, $itemRow[12]); // PO NO
        $this->assertNull($itemRow[13]); // SALES PERSON — no data path in this schema, always blank
        $this->assertEquals('SMD', $itemRow[14]); // LOCATION = warehouse.code
        $this->assertNull($itemRow[15]); // DEPARTMENT
        $this->assertNull($itemRow[16]); // PROJECT
        $this->assertNull($itemRow[17]); // BRANCH
    }

    public function test_export_route_requires_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/goods-receipts/export/listing?mode=summary')->assertForbidden();
    }

    public function test_export_route_is_distinct_from_the_unrelated_reports_export(): void
    {
        $this->submittedPurchaseOrder(qty: 1, rate: 100000);

        Permission::query()->firstOrCreate(['name' => 'purchase.goods_receipts.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('purchase.goods_receipts.view');
        Sanctum::actingAs($viewer);

        // /goods-receipts/export (no /listing) still resolves to the older, unrelated export.
        $plain = $this->get('/api/v1/goods-receipts/export?format=xlsx');
        $plain->assertOk();

        $listing = $this->get('/api/v1/goods-receipts/export/listing?mode=summary&format=xlsx');
        $listing->assertOk();
    }

    public function test_export_route_downloads_a_real_xlsx_matching_the_reference_template_layout(): void
    {
        $tax = $this->makeTax();
        $po = $this->submittedPurchaseOrder(qty: 10, rate: 100000, taxId: $tax->id, remarks: 'TUJUAN SAMARINDA');

        $this->goodsReceiptService->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 10]],
        ]);

        Permission::query()->firstOrCreate(['name' => 'purchase.goods_receipts.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('purchase.goods_receipts.view');
        Sanctum::actingAs($viewer);

        $response = $this->get('/api/v1/goods-receipts/export/listing?mode=summary&format=xlsx');
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'gr-listing') . '.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();

        $this->assertEquals('GOODS RECEIVE NOTE LISTING - SUMMARY', $sheet->getCell('A1')->getValue());
        $this->assertStringNotContainsString('Base Currency', $sheet->getCell('A2')->getValue()); // no currency suffix, unlike PO
        $this->assertEquals('PT. KALINDO ETAM', $sheet->getCell('A5')->getValue());
        $this->assertNotEmpty($sheet->getCell('I5')->getValue()); // timestamp at column I for GR Summary — verified against the reference file, NOT column D like PO
        $this->assertEquals('Date', $sheet->getCell('A8')->getValue()); // not all-caps, unlike PO
        $this->assertEquals('Excl.Tax', $sheet->getCell('F8')->getValue());
        $this->assertEquals('#,##0.00', $sheet->getStyle('F9')->getNumberFormat()->getFormatCode());
        $this->assertEquals(1000000.0, $sheet->getCell('F9')->getValue());
        $this->assertEquals('Total By Header', $sheet->getCell('E10')->getValue());

        unlink($tmpPath);
    }

    /**
     * Mirrors the migration's own SELECT+UPDATE shape directly against the test's in-memory
     * sqlite connection (RefreshDatabase already ran every migration, including this one, before
     * this test — so the column exists but starts null for rows created before this assertion by
     * the fixtures below since GoodsReceiptService already populates it going forward; this test
     * instead forces a pre-migration-shaped row to prove the backfill query itself is correct).
     */
    public function test_backfill_migration_recomputes_tax_against_the_gr_items_own_amount(): void
    {
        $tax = $this->makeTax();
        $po = $this->submittedPurchaseOrder(qty: 10, rate: 100000, taxId: $tax->id);

        $gr = $this->goodsReceiptService->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 10]],
        ]);

        // Simulate a pre-ticket row: tax already stripped back to null/0, as every real historical
        // row is (RefreshDatabase already ran this migration once as part of setUp(), which is a
        // no-op against a row that doesn't exist yet — re-running its up() directly, the same
        // entry point a real `php artisan migrate` deploy uses, is what actually needs testing).
        DB::table('goods_receipt_items')->where('id', $gr->items->first()->id)->update(['tax_id' => null, 'tax_amount' => 0]);

        (require database_path('migrations/2026_09_10_000002_backfill_goods_receipt_item_tax_from_purchase_order.php'))->up();

        $line = $gr->items->first()->fresh();
        $this->assertEquals($tax->id, $line->tax_id);
        $this->assertEquals(110000, (float) $line->tax_amount);
    }
}
