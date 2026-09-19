<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Enums\WarehouseType;
use App\Models\GoodsReceipt;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Import\PurchaseHistoryImportService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises PurchaseHistoryImportService end-to-end, all 3 source file shapes (Supplier Purchase
 * Listing, Product Purchase Report, Purchase Order Tracking) — see the class docblock on
 * PurchaseHistoryImportService for what each one creates (or, for Product Purchase Report,
 * deliberately doesn't).
 */
class PurchaseHistoryImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseHistoryImportService $service;

    protected Warehouse $warehouse;

    protected Item $placeholderItem;

    protected ItemGroup $itemGroup;

    protected UnitOfMeasurement $uom;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        Permission::query()->firstOrCreate(['name' => 'purchase.orders.approve', 'guard_name' => 'web']);

        $this->service = app(PurchaseHistoryImportService::class);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $this->uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->placeholderItem = $this->makeItem('PLACEHOLDER', 'Placeholder Line Item');
    }

    private function makeItem(string $code, string $name): Item
    {
        return Item::query()->create([
            'item_code' => $code,
            'item_name' => $name,
            'item_group_id' => $this->itemGroup->id,
            'uom_id' => $this->uom->id,
            'standard_rate' => 0,
        ]);
    }

    private function csv(array $rows): string
    {
        return implode("\r\n", array_map(
            fn (array $row) => implode(',', array_map(fn ($v) => $v ?? '', $row)),
            $rows
        ))."\r\n";
    }

    /** @param  'supplier_purchase_listing'|'product_purchase_report'|'purchase_order_tracking'  $type */
    private function makeBatch(string $type, string $csv, array $resolutions = [], ?User $creator = null): ImportBatch
    {
        $path = 'imports/test-'.$type.'-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'purchase-history',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => [
                'type' => $type,
                'warehouse_id' => $this->warehouse->id,
                'placeholder_item_id' => $this->placeholderItem->id,
            ],
            'fk_resolutions' => $resolutions !== [] ? $resolutions : null,
            'created_by' => $creator?->id,
        ]);
    }

    // Row 4 needs real (blank) comma-separated cells, not a truly empty line — a genuinely
    // empty CSV line is dropped entirely by ImportFileReader::readRawCsv(), which would shift
    // every later row index by one versus how a real .xlsx file preserves the blank row. Matches
    // the REAL file's shape (no Document No/Date/Supplier at all — a pure per-item aggregate).
    private const PPR_PREAMBLE = [
        ['PRODUCT PURCHASE REPORT - SUMMARY'],
        ['Date From', 'Date To', 'Include Tax Y/N'],
        ['01/01/2026', '31/01/2026', 'Yes'],
        ['PT KALINDO ETAM', '', '', '', '', '', '', '', ''],
        ['ITEM #', 'DESCRIPTION', 'INV', 'DN', 'TOTAL', 'CN', 'NET AMT', 'QTY. PUR.', 'CN. QTY.', 'NET QTY.'],
    ];

    /**
     * File B is a pure per-item, per-category period aggregate — no document/date/supplier
     * anywhere in it, so it never creates a Purchase Order or Goods Receipt. Category rows,
     * "Sub-Total [...]" rows, and the trailing "Printed By :" grand total are all structural noise
     * that must never be counted as items, and the same item code appearing in two places (it
     * shouldn't in a real file, but defensively) must still sum into one snapshot row.
     */
    public function test_category_subtotal_and_grand_total_rows_are_skipped_leaving_only_real_items(): void
    {
        $this->makeItem('BDR20', 'BENDRAT 20KG');

        $csv = $this->csv([
            ...self::PPR_PREAMBLE,
            ['KAWAT', '', '', '', '', '', '', '', '', ''],
            ['BDR20', 'BENDRAT 20KG', 0, 0, 100000, 0, 100000, 10, 0, 10],
            ['', 'Sub-Total [KAWAT]', 0, 0, 100000, 0, 100000, 10, 0, 10],
            ['Printed By :', 'ADMIN', 0, 0, 100000, 0, 100000, 10, 0, 10],
        ]);

        $batch = $this->makeBatch('product_purchase_report', $csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(1, $batch->total_rows, 'only the one real item row — category/sub-total/grand-total are structural noise');
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, GoodsReceipt::query()->count());

        $snapshot = $batch->preview_summary['item_snapshot'];
        $this->assertCount(1, $snapshot);
        $this->assertSame('BDR20', $snapshot[0]['item_code']);
        $this->assertEquals(10, $snapshot[0]['qty']);
        $this->assertEquals(100000, $snapshot[0]['amount']);
        $this->assertEquals(10000, $snapshot[0]['avg_price'], 'avg_price = amount / qty, computed since the file has no price column');
        $this->assertSame('2026-01-01', $batch->preview_summary['period_from']);
        $this->assertSame('2026-01-31', $batch->preview_summary['period_to']);
    }

    public function test_item_resolution_map_and_skip_are_reflected_in_the_snapshot_not_a_document(): void
    {
        $existingItem = $this->makeItem('EXIST1', 'Existing Mapped Item');

        $csv = $this->csv([
            ...self::PPR_PREAMBLE,
            ['MISC', '', '', '', '', '', '', '', '', ''],
            ['MAPCODE', 'Some Item Name', 0, 0, 30000, 0, 30000, 3, 0, 3],
            ['SKIPCODE', 'Skip Item Name', 0, 0, 20000, 0, 20000, 2, 0, 2],
        ]);

        $batch = $this->makeBatch('product_purchase_report', $csv, [
            'item' => ['MAPCODE' => ['action' => 'map', 'target_id' => $existingItem->id]],
            // SKIPCODE deliberately has no resolution entry — defaults to skip.
        ]);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows, 'unresolved master data is reported as needs_review, never a hard failure');
        $this->assertSame(1, $batch->preview_summary['needs_review_rows']);

        $snapshot = collect($batch->preview_summary['item_snapshot'])->keyBy('item_code');
        $this->assertTrue($snapshot['MAPCODE']['resolved']);
        $this->assertSame($existingItem->id, $snapshot['MAPCODE']['item_id']);
        $this->assertFalse($snapshot['SKIPCODE']['resolved']);
        $this->assertNull($snapshot['SKIPCODE']['item_id']);

        $this->assertSame(0, PurchaseOrder::query()->count(), 'File B never creates any document');
        $this->assertSame(0, GoodsReceipt::query()->count());
    }

    private const SPL_PREAMBLE = [
        ['SUPPLIER PURCHASE LISTING'],
        ['', '', '', '19/08/2026 - 19/09/2026'],
        ['PT KALINDO ETAM', '', '', '', '19/09/2026 12:17:39'],
        ['', '', '', '', '', '', '', '', ''],
        ['DATE', 'DOCUMENT #', 'REFERENCE #', 'REFERENCE 2 #', 'SUPPLIER CODE', 'SUPPLIER NAME', 'TYPE', 'AMOUNT (EXCLUDE TAX)', 'TAX', 'AMOUNT (INCLUDE TAX)'],
    ];

    private const POT_PREAMBLE = [
        ['PURCHASE ORDER TRACKING'],
        ['PT KALINDO ETAM'],
        ['01/01/2026 - 31/01/2026'],
        ['', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['PO DATE', 'PO NO', 'SUPPLIER NAME', 'AMOUNT', 'QUOTE NO.', 'REQUEST BY', 'INVOICE DATE', 'SUPPLIER INVOICE NO.', 'SUPPLIER DO NO.', 'SUP. DO DATE', 'REQUISITION #', 'AMOUNT BILLED', 'OUTSTD GRN', 'OUTSTD PO'],
    ];

    private function approverUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchase.orders.approve');

        return $user;
    }

    public function test_po_tracking_row_without_grn_creates_and_auto_approves_a_purchase_order_only(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);

        $csv = $this->csv([
            ...self::POT_PREAMBLE,
            ['05/01/2026', 'PO-100', 'PT ABC', 500000, '', '', '', '', '', '', '', '', '', ''],
        ]);

        $batch = $this->makeBatch('purchase_order_tracking', $csv, creator: $this->approverUser());
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(1, $batch->success_rows);

        $po = PurchaseOrder::query()->where('source_document_number', 'PO-100')->with('items')->firstOrFail();
        $this->assertSame('submitted', $po->status->value, 'auto-approved and submitted during import');
        $this->assertCount(1, $po->items);
        $this->assertSame($this->placeholderItem->id, $po->items->first()->item_id);
        $this->assertEquals(500000, (float) $po->items->first()->rate);
        $this->assertSame(0, GoodsReceipt::query()->where('purchase_order_id', $po->id)->count(), 'no GRN info on this row — no receipt created');
    }

    public function test_po_tracking_row_with_grn_also_creates_a_linked_goods_receipt(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);

        $csv = $this->csv([
            ...self::POT_PREAMBLE,
            ['05/01/2026', 'PO-101', 'PT ABC', 500000, '', '', '', '', 'DO-999', '10/01/2026', '', '', '', ''],
        ]);

        $batch = $this->makeBatch('purchase_order_tracking', $csv, creator: $this->approverUser());
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);

        $po = PurchaseOrder::query()->where('source_document_number', 'PO-101')->with('items')->firstOrFail();
        $receipt = GoodsReceipt::query()->where('source_document_number', 'DO-999')->with('items')->firstOrFail();

        $this->assertSame($po->id, $receipt->purchase_order_id);
        $this->assertSame('submitted', $receipt->status->value);
        $this->assertSame($po->items->first()->id, $receipt->items->first()->purchase_order_item_id);
        $this->assertEquals(1, (float) $receipt->items->first()->qty);
    }

    public function test_po_tracking_rejects_whole_import_upfront_when_importing_user_lacks_approve_permission(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);

        $csv = $this->csv([
            ...self::POT_PREAMBLE,
            ['05/01/2026', 'PO-102', 'PT ABC', 500000, '', '', '', '', '', '', '', '', '', ''],
        ]);

        $noPermissionUser = User::factory()->create();
        $batch = $this->makeBatch('purchase_order_tracking', $csv, creator: $noPermissionUser);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::FAILED, $batch->status);
        $this->assertStringContainsString('tidak memiliki izin approve', $batch->failure_reason);
        $this->assertSame(0, PurchaseOrder::query()->count(), 'nothing is created once the upfront check fails');
    }

    public function test_po_tracking_duplicate_po_number_is_skipped_by_default_but_can_be_created_anyway(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);

        $csv = $this->csv([
            ...self::POT_PREAMBLE,
            ['05/01/2026', 'PO-103', 'PT ABC', 500000, '', '', '', '', '', '', '', '', '', ''],
        ]);

        $first = $this->makeBatch('purchase_order_tracking', $csv, creator: $this->approverUser());
        $this->service->import($first);
        $this->assertSame(1, PurchaseOrder::query()->where('source_document_number', 'PO-103')->count());

        $again = $this->makeBatch('purchase_order_tracking', $csv, creator: $this->approverUser());
        $this->service->import($again);
        $this->assertSame(1, PurchaseOrder::query()->where('source_document_number', 'PO-103')->count(), 'skipped by default');

        $proceed = $this->makeBatch('purchase_order_tracking', $csv, [
            'duplicate' => ['PO-103' => ['action' => 'proceed', 'target_id' => null]],
        ], $this->approverUser());
        $this->service->import($proceed);
        $this->assertSame(2, PurchaseOrder::query()->where('source_document_number', 'PO-103')->count(), 'explicit override creates it anyway');
    }

    /**
     * The real attached sample files (xlsProductPurchaseReport.xlsx / xlsPurchaseOrderTracking.xlsx,
     * project root) — with no master data seeded to match, every supplier is unresolved by design,
     * so this is a parser/orchestration smoke test (completes cleanly, nothing crashes, every row
     * lands as a reported "needs_review"), not a full data-matching run.
     */
    public function test_real_product_purchase_report_file_completes_without_crashing(): void
    {
        $realFile = dirname(__DIR__, 4).'/xlsProductPurchaseReport.xlsx';

        if (! file_exists($realFile)) {
            $this->markTestSkipped('Real xlsProductPurchaseReport.xlsx sample not present in the project root.');
        }

        $path = 'imports/real-ppr.xlsx';
        Storage::disk('local')->put($path, file_get_contents($realFile));

        $batch = ImportBatch::query()->create([
            'module' => 'purchase-history',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'xlsProductPurchaseReport.xlsx',
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['type' => 'product_purchase_report', 'warehouse_id' => $this->warehouse->id, 'placeholder_item_id' => $this->placeholderItem->id],
        ]);

        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertGreaterThan(0, $batch->total_rows);
        $this->assertSame(0, $batch->failed_rows, 'unresolved master data is reported as needs_review, never a hard failure');
        $this->assertNotEmpty($batch->preview_summary['item_snapshot'], 'the real file must actually parse into item rows now, not silently drop everything');
        $this->assertSame(0, PurchaseOrder::query()->count(), 'File B never creates a document');
        $this->assertSame(0, GoodsReceipt::query()->count());
    }

    /**
     * With no master data seeded to match the real file, every supplier is unresolved by design —
     * same smoke-test posture as the other 2 real-file tests (parser/orchestration only, not a
     * full data-matching run). The synthetic-CSV test below covers the actual PO creation.
     */
    public function test_real_supplier_purchase_listing_file_completes_without_crashing(): void
    {
        $realFile = dirname(__DIR__, 4).'/xlsSupplierPurchaseListing.xlsx';

        if (! file_exists($realFile)) {
            $this->markTestSkipped('Real xlsSupplierPurchaseListing.xlsx sample not present in the project root.');
        }

        $path = 'imports/real-spl.xlsx';
        Storage::disk('local')->put($path, file_get_contents($realFile));

        $batch = ImportBatch::query()->create([
            'module' => 'purchase-history',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'xlsSupplierPurchaseListing.xlsx',
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['type' => 'supplier_purchase_listing', 'warehouse_id' => $this->warehouse->id, 'placeholder_item_id' => $this->placeholderItem->id],
            'created_by' => $this->approverUser()->id,
        ]);

        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertGreaterThan(0, $batch->total_rows);
        $this->assertSame(0, PurchaseOrder::query()->where('import_source_type', 'po_tracking_amount')->count(), 'the two import origins are never conflated');
    }

    public function test_supplier_purchase_listing_creates_one_po_per_document_number_tagged_as_historical_invoice(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);

        $csv = $this->csv([
            ...self::SPL_PREAMBLE,
            ['19/08/2026', 'BRM/041/038', 'FJ/KE/2026/041/038', 'PENGANGKUTAN', 'S-0027', 'PT ABC', 'SupInv', 30857736, 0, 30857736],
        ]);

        $batch = $this->makeBatch('supplier_purchase_listing', $csv, creator: $this->approverUser());
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(1, $batch->success_rows);

        $po = PurchaseOrder::query()->where('source_document_number', 'BRM/041/038')->with('items')->firstOrFail();
        $this->assertSame('submitted', $po->status->value, 'auto-approved and submitted during import, same as Purchase Order Tracking');
        $this->assertSame('historical_invoice', $po->import_source_type);
        $this->assertEquals(30857736, (float) $po->total_amount);
        $this->assertSame('FJ/KE/2026/041/038', $po->import_extra['reference_no']);
        $this->assertNull($po->amount_billed, 'AMOUNT BILLED has no equivalent in this file — must stay null, never fabricated');
    }

    public function test_supplier_purchase_listing_duplicate_document_number_is_skipped_by_default(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);

        $csv = $this->csv([
            ...self::SPL_PREAMBLE,
            ['24/08/2026', '1312', '', '', 'S-0275', 'PT ABC', 'SupInv', 480000, 0, 480000],
        ]);

        $first = $this->makeBatch('supplier_purchase_listing', $csv, creator: $this->approverUser());
        $this->service->import($first);
        $this->assertSame(1, PurchaseOrder::query()->where('source_document_number', '1312')->count());

        $again = $this->makeBatch('supplier_purchase_listing', $csv, creator: $this->approverUser());
        $this->service->import($again);
        $this->assertSame(1, PurchaseOrder::query()->where('source_document_number', '1312')->count(), 'skipped by default');

        $proceed = $this->makeBatch('supplier_purchase_listing', $csv, [
            'duplicate' => ['1312' => ['action' => 'proceed', 'target_id' => null]],
        ], $this->approverUser());
        $this->service->import($proceed);
        $this->assertSame(2, PurchaseOrder::query()->where('source_document_number', '1312')->count(), 'explicit override creates it anyway');
    }

    public function test_supplier_purchase_listing_preflight_always_warns_it_wont_appear_in_by_supplier_and_is_not_a_real_invoice(): void
    {
        $csv = $this->csv([
            ...self::SPL_PREAMBLE,
            ['19/08/2026', 'BRM/041/038', '', '', '', 'PT ABC', 'SupInv', 30857736, 0, 30857736],
        ]);

        $path = 'imports/spl-preflight.csv';
        Storage::disk('local')->put($path, $csv);

        $preflight = $this->service->preflight(Storage::disk('local')->path($path), 'csv');

        $this->assertSame('supplier_purchase_listing', $preflight['type']);
        $warnings = implode(' ', $preflight['warnings']);
        $this->assertStringContainsString('By Supplier', $warnings);
        $this->assertStringContainsString('bukan Purchase Invoice resmi', $warnings);
    }

    public function test_real_purchase_order_tracking_file_completes_without_crashing(): void
    {
        $realFile = dirname(__DIR__, 4).'/xlsPurchaseOrderTracking.xlsx';

        if (! file_exists($realFile)) {
            $this->markTestSkipped('Real xlsPurchaseOrderTracking.xlsx sample not present in the project root.');
        }

        $path = 'imports/real-pot.xlsx';
        Storage::disk('local')->put($path, file_get_contents($realFile));

        $batch = ImportBatch::query()->create([
            'module' => 'purchase-history',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'xlsPurchaseOrderTracking.xlsx',
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['type' => 'purchase_order_tracking', 'warehouse_id' => $this->warehouse->id, 'placeholder_item_id' => $this->placeholderItem->id],
            'created_by' => $this->approverUser()->id,
        ]);

        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertGreaterThan(0, $batch->total_rows);
        $this->assertSame(0, $batch->failed_rows, 'unresolved master data is reported as needs_review, never a hard failure');
    }
}
