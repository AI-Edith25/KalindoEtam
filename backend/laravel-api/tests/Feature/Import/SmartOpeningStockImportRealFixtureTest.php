<?php

namespace Tests\Feature\Import;

use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\NamingSeries;
use App\Models\OpeningStock;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exercises SmartOpeningStockImportService against 2 real legacy exports:
 *
 * - xlsFIFOOpeningQuantity.xlsx: header at row 8 (7 title/company rows above
 *   it), 5 data rows across 2 warehouses (BTG/SMD), a trailing "Grand Total"
 *   row with text mixed into numeric columns, no duplicates.
 * - xlsStockBalance.xlsx: header at row 5, ~109 data rows across many
 *   warehouses, NO per-row Date column at all (relies on the metadata
 *   cutoff date), a trailing "TOTAL" row (label in the Item Group column,
 *   a different column than the FIFO file's footer), and 3 genuine
 *   duplicate (item, warehouse) combinations to sum.
 *
 * Only a deliberate subset of item/warehouse codes are seeded — the rest of
 * each file's codes are expected to surface as unmatched_items, which is
 * itself the behavior under test (a big legacy file with mostly-unknown
 * codes must still produce a usable partial preview, never one all-or-
 * nothing failure).
 */
class SmartOpeningStockImportRealFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'inventory.opening_stock.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.opening_stock.import');
        Sanctum::actingAs($user);

        NamingSeries::query()->create(['module' => 'inventory', 'document_type' => 'opening_stock', 'prefix' => 'OS-', 'digit_length' => 5]);
    }

    private function fixtureFile(string $name): UploadedFile
    {
        return new UploadedFile(
            base_path("tests/Fixtures/{$name}"),
            $name,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    private function seedMaster(): array
    {
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Unit']);
        $btg = Warehouse::query()->create(['code' => 'BTG', 'name' => 'Bontang', 'warehouse_type' => WarehouseType::MAIN]);
        $smd = Warehouse::query()->create(['code' => 'SMD', 'name' => 'Samarinda', 'warehouse_type' => WarehouseType::MAIN]);
        $bpp = Warehouse::query()->create(['code' => 'BPP', 'name' => 'Balikpapan', 'warehouse_type' => WarehouseType::MAIN]);

        return compact('itemGroup', 'uom', 'btg', 'smd', 'bpp');
    }

    private function makeItem(string $code, ItemGroup $group, UnitOfMeasurement $uom, string $qtyCategory = 'unit'): Item
    {
        return Item::query()->create(['item_code' => $code, 'item_name' => $code, 'item_group_id' => $group->id, 'uom_id' => $uom->id, 'qty_category' => $qtyCategory]);
    }

    public function test_fifo_opening_quantity_detects_header_skips_footer_and_groups_by_warehouse(): void
    {
        $master = $this->seedMaster();
        foreach (['SDELUXE_5/8', 'SFIGO_1/2', 'SFIGO_5/8'] as $code) {
            $this->makeItem($code, $master['itemGroup'], $master['uom']);
        }

        $upload = $this->post('/api/v1/opening-stock/smart-import', ['file' => $this->fixtureFile('xlsFIFOOpeningQuantity.xlsx')]);
        $upload->assertCreated();

        $summary = $upload->json('data.preview_summary');

        // 5 real data rows -- the Grand Total row (text mixed into numeric columns) must never
        // be counted as a row at all, let alone break parsing.
        $this->assertSame(5, $summary['total_rows']);
        $this->assertSame(5, $summary['valid_row_count']);
        $this->assertSame([], $summary['skipped_rows']);
        $this->assertSame([], $summary['unmatched_items']);
        $this->assertSame([], $summary['unmatched_warehouses']);
        $this->assertSame([], $summary['price_conflicts']);
        $this->assertSame(['BTG' => 3, 'SMD' => 2], $summary['warehouses_detected']);

        $groups = collect($summary['groups'])->keyBy('warehouse_code');
        $this->assertCount(2, $groups);
        $this->assertSame('2022-09-30', $groups['BTG']['cutoff_date']);
        $this->assertEqualsWithDelta(4.0, $groups['BTG']['total_qty'], 0.001);
        $this->assertEqualsWithDelta(9.0, $groups['SMD']['total_qty'], 0.001);

        $batchId = $upload->json('data.id');
        $resolve = $this->post("/api/v1/opening-stock/smart-import/{$batchId}/resolve");
        $resolve->assertOk();
        $this->assertSame('completed', $resolve->json('data.status'));
        $this->assertSame(2, $resolve->json('data.success_rows'));
        $this->assertSame(0, $resolve->json('data.failed_rows'));

        $this->assertSame(2, OpeningStock::query()->count());
        $btgDoc = OpeningStock::query()->where('warehouse_id', $master['btg']->id)->first();
        $this->assertNotNull($btgDoc);
        $this->assertCount(3, $btgDoc->items);
    }

    public function test_stock_balance_sums_duplicates_and_falls_back_to_metadata_date(): void
    {
        $master = $this->seedMaster();
        // Cement quantities are legitimately fractional (tons/sacks) in the real data --
        // qty_category 'weight' allows decimals, unlike the default 'unit' (integer-only).
        $scOpcJb = $this->makeItem('SC OPC JB', $master['itemGroup'], $master['uom'], 'weight');
        $this->makeItem('CAT', $master['itemGroup'], $master['uom']);

        $upload = $this->post('/api/v1/opening-stock/smart-import', [
            'file' => $this->fixtureFile('xlsStockBalance.xlsx'),
            'metadata_cutoff_date' => '2026-09-20',
        ]);
        $upload->assertCreated();

        $summary = $upload->json('data.preview_summary');

        // The TOTAL row's label sits in the Item Group column here (a different column than
        // the FIFO file's footer) -- still must never be counted as a row.
        $this->assertSame(109, $summary['total_rows']);

        // Only the 2 seeded item codes resolve; every other real code in this ~109-row file
        // is expected to show up as unmatched rather than aborting the whole import.
        $unmatchedCodes = collect($summary['unmatched_items'])->pluck('item_code')->all();
        $this->assertNotContains('SC OPC JB', $unmatchedCodes);
        $this->assertNotContains('CAT', $unmatchedCodes);
        $this->assertGreaterThan(50, count($unmatchedCodes));

        // SC OPC JB @ SMD appears twice in the file (98.88 and 2158.519, same price) -- summed
        // into one line, not two.
        $smdGroup = collect($summary['groups'])->firstWhere('warehouse_code', 'SMD');
        $this->assertNotNull($smdGroup);
        $this->assertSame('2026-09-20', $smdGroup['cutoff_date']);
        $scOpcLine = collect($smdGroup['lines'])->firstWhere('item_code', 'SC OPC JB');
        $this->assertNotNull($scOpcLine);
        $this->assertEqualsWithDelta(98.88 + 2158.519, $scOpcLine['qty'], 0.001);

        $batchId = $upload->json('data.id');
        $resolve = $this->post("/api/v1/opening-stock/smart-import/{$batchId}/resolve");
        $resolve->assertOk();

        $smdDoc = OpeningStock::query()->where('warehouse_id', $master['smd']->id)->first();
        $this->assertNotNull($smdDoc);
        $line = $smdDoc->items->firstWhere('item_id', $scOpcJb->id);
        $this->assertNotNull($line);
        // Rounded to 2dp by QtyCategoryValidator (item's qty_category is 'weight') -- the sum
        // itself (98.88 + 2158.519 = 2257.399) is correct, storage rounding is separate and expected.
        $this->assertEqualsWithDelta(round(98.88 + 2158.519, 2), (float) $line->qty, 0.001);
    }

    /**
     * Real bug: BPP's SC PCC 50 KG row is B/F 110744.999, IN 45412, OUT 53091 -- the legacy
     * export's own BALANCE column already carries this as 103065.999, not a clean 103066, from
     * precision computed upstream (see cleanWholeNumberDrift()'s docblock). SC PCC 50 KG is
     * qty_category 'unit' (a ZAK/sack count genuinely must be whole) -- before the fix this
     * whole import failed at resolve() with "Item ini dihitung per satuan (ZAK)." even though a
     * human reading the file sees a clean whole number.
     */
    public function test_stock_balance_tolerates_float_drift_on_whole_number_item(): void
    {
        $master = $this->seedMaster();
        $this->makeItem('SC PCC 50 KG', $master['itemGroup'], $master['uom'], 'unit');

        $upload = $this->post('/api/v1/opening-stock/smart-import', [
            'file' => $this->fixtureFile('xlsStockBalance.xlsx'),
            'metadata_cutoff_date' => '2026-09-20',
        ]);
        $upload->assertCreated();

        $summary = $upload->json('data.preview_summary');
        $bppGroup = collect($summary['groups'])->firstWhere('warehouse_code', 'BPP');
        $this->assertNotNull($bppGroup);
        $line = collect($bppGroup['lines'])->firstWhere('item_code', 'SC PCC 50 KG');
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(103066.0, (float) $line['qty'], 0.001);

        $batchId = $upload->json('data.id');
        $resolve = $this->post("/api/v1/opening-stock/smart-import/{$batchId}/resolve");
        $resolve->assertOk();
        $this->assertSame(0, $resolve->json('data.failed_rows'));

        $bppDoc = OpeningStock::query()->where('warehouse_id', $master['bpp']->id)->first();
        $this->assertNotNull($bppDoc);
        $bppLine = $bppDoc->items->firstWhere('item_code', 'SC PCC 50 KG');
        $this->assertNotNull($bppLine);
        $this->assertEqualsWithDelta(103066.0, (float) $bppLine->qty, 0.001);
    }
}
