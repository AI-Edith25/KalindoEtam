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
 * Exercises PurchaseHistoryImportService end-to-end. Unlike every other importer this session
 * (which posts a raw Journal Entry), this one posts real Purchase Order / Goods Receipt documents —
 * see the approved plan for why (By Supplier/By Item/PO Tracking are computed straight from
 * submitted PO/GRN, no report table exists).
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

    /** @param  'product_purchase_report'|'purchase_order_tracking'  $type */
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
    // every later row index by one versus how a real .xlsx file preserves the blank row.
    private const PPR_PREAMBLE = [
        ['PRODUCT PURCHASE REPORT'],
        ['PT KALINDO ETAM'],
        ['01/01/2026 - 31/01/2026'],
        ['', '', '', '', '', '', '', '', '', '', ''],
        ['DATE', 'DOCUMENT #', 'SUPPLIER NAME', 'INV', 'DN', 'TOTAL', 'CN', 'NET AMT', 'QTY. PUR.', 'CN. QTY.', 'NET QTY.'],
    ];

    public function test_scattered_groups_are_buffered_bonus_line_gets_zero_rate_and_mismatch_is_flagged_as_warning(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);
        $this->makeItem('BDR20', 'BENDRAT 20KG');
        $this->makeItem('BSI10', 'BESI 10MM');
        $this->makeItem('BON01', 'BONUS ITEM');

        $csv = $this->csv([
            ...self::PPR_PREAMBLE,
            ['KAWAT', '', '', '', '', '', '', '', '', '', ''],
            ['BDR20 - BENDRAT 20KG', '', '', '', '', '', '', '', '', '', ''],
            ['01/01/2026', 'SI-001', 'PT ABC', '', '', 100000, 0, 100000, 10, 0, 10],
            ['BESI', '', '', '', '', '', '', '', '', '', ''],
            ['BSI10 - BESI 10MM', '', '', '', '', '', '', '', '', '', ''],
            ['01/01/2026', 'SI-001', 'PT ABC', '', '', 50000, 0, 50000, 5, 0, 5], // scattered: same doc, different item section
            ['BONUS', '', '', '', '', '', '', '', '', '', ''],
            ['BON01 - BONUS ITEM', '', '', '', '', '', '', '', '', '', ''],
            ['02/01/2026', 'DO-002 BONUS', 'PT ABC', '', '', 0, 0, 0, 3, 0, 3], // free goods, real qty, zero money
            ['BDR20 - BENDRAT 20KG', '', '', '', '', '', '', '', '', '', ''],
            ['03/01/2026', 'SI-003', 'PT ABC', '', '', 200000, 50000, 200000, 20, 0, 20], // NET AMT != TOTAL - CN
        ]);

        $batch = $this->makeBatch('product_purchase_report', $csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(3, $batch->success_rows, 'SI-001, DO-002 BONUS, SI-003 — one receipt each');
        $this->assertSame(0, $batch->failed_rows);

        $si001 = GoodsReceipt::query()->where('source_document_number', 'SI-001')->with('items')->firstOrFail();
        $this->assertCount(2, $si001->items, 'the second line, from a scattered later section, must still land in the same group');
        $this->assertEquals(10000, (float) $si001->items->first(fn ($i) => $i->item_code === 'BDR20')->rate);
        $this->assertEquals(10000, (float) $si001->items->first(fn ($i) => $i->item_code === 'BSI10')->rate);

        $bonus = GoodsReceipt::query()->where('source_document_number', 'DO-002 BONUS')->with('items')->firstOrFail();
        $this->assertEquals(3, (float) $bonus->items->first()->qty);
        $this->assertEquals(0, (float) $bonus->items->first()->rate);

        $warning = collect($batch->preview_summary['vouchers'])->first(fn ($v) => str_contains((string) $v['reason'], 'SI-003'));
        $this->assertNotNull($warning, 'the NET AMT vs TOTAL-CN mismatch must be reported as a non-blocking warning');

        $si003 = GoodsReceipt::query()->where('source_document_number', 'SI-003')->firstOrFail();
        $this->assertSame('submitted', $si003->status->value, 'a flagged mismatch still gets created and submitted, never blocked');
    }

    public function test_supplier_create_item_map_and_item_skip_resolutions_are_applied(): void
    {
        $existingItem = $this->makeItem('EXIST1', 'Existing Mapped Item');

        $csv = $this->csv([
            ...self::PPR_PREAMBLE,
            ['MISC', '', '', '', '', '', '', '', '', '', ''],
            ['MAPCODE - Some Item Name', '', '', '', '', '', '', '', '', '', ''],
            ['10/01/2026', 'SI-010', 'PT NEW SUPPLIER', '', '', 30000, 0, 30000, 3, 0, 3],
            ['SKIPCODE - Skip Item Name', '', '', '', '', '', '', '', '', '', ''],
            ['10/01/2026', 'SI-010', 'PT NEW SUPPLIER', '', '', 20000, 0, 20000, 2, 0, 2],
        ]);

        $batch = $this->makeBatch('product_purchase_report', $csv, [
            'supplier' => ['PT NEW SUPPLIER' => ['action' => 'create', 'target_id' => null]],
            'item' => ['MAPCODE' => ['action' => 'map', 'target_id' => $existingItem->id]],
            // SKIPCODE deliberately has no resolution entry — defaults to skip.
        ]);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);

        $supplier = Supplier::query()->where('supplier_name', 'PT NEW SUPPLIER')->first();
        $this->assertNotNull($supplier, 'unresolved supplier with a "create" decision must be created as new master data');
        $this->assertStringStartsWith('LEGACY-', $supplier->supplier_code);

        $receipt = GoodsReceipt::query()->where('source_document_number', 'SI-010')->with('items')->firstOrFail();
        $this->assertCount(1, $receipt->items, 'the unresolved SKIPCODE line must be dropped, not block the whole group');
        $this->assertSame($existingItem->id, $receipt->items->first()->item_id);

        $reason = collect($batch->preview_summary['vouchers'])->first(fn ($v) => $v['document_number'] === 'SI-010')['reason'];
        $this->assertStringContainsString('SKIPCODE', $reason);
    }

    public function test_duplicate_document_number_is_skipped_by_default_but_can_be_created_anyway(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);
        $this->makeItem('BDR20', 'BENDRAT 20KG');

        $csv = $this->csv([
            ...self::PPR_PREAMBLE,
            ['KAWAT', '', '', '', '', '', '', '', '', '', ''],
            ['BDR20 - BENDRAT 20KG', '', '', '', '', '', '', '', '', '', ''],
            ['01/01/2026', 'SI-020', 'PT ABC', '', '', 100000, 0, 100000, 10, 0, 10],
        ]);

        $first = $this->makeBatch('product_purchase_report', $csv);
        $this->service->import($first);
        $this->assertSame(1, GoodsReceipt::query()->where('source_document_number', 'SI-020')->count());

        $again = $this->makeBatch('product_purchase_report', $csv);
        $this->service->import($again);
        $again->refresh();
        $this->assertSame(1, GoodsReceipt::query()->where('source_document_number', 'SI-020')->count(), 'skipped by default');
        $reason = collect($again->preview_summary['vouchers'])->first(fn ($v) => $v['document_number'] === 'SI-020')['reason'];
        $this->assertStringContainsString('sudah pernah diimpor', $reason);

        $proceed = $this->makeBatch('product_purchase_report', $csv, [
            'duplicate' => ['SI-020' => ['action' => 'proceed', 'target_id' => null]],
        ]);
        $this->service->import($proceed);
        $this->assertSame(2, GoodsReceipt::query()->where('source_document_number', 'SI-020')->count(), 'explicit override creates it anyway');
    }

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
