<?php

namespace Tests\Feature\Import;

use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\StockAdjustment;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SmartStockAdjustmentImportService against the same real xlsStockBalance.xlsx fixture
 * SmartOpeningStockImportRealFixtureTest uses — covering what that importer structurally can't:
 * reconciling CURRENT stock to a newer snapshot for items that already have prior activity.
 *
 * Fixture rows used here (see SmartOpeningStockImportRealFixtureTest's own docblock for the
 * file's general shape): "SC PCC 50 KG" @ BPP, BALANCE 103,066.00, UNIT PRICE 31,531.54 — a real
 * correction case. "CAT" @ BPP, BALANCE 25,121.00, UNIT PRICE 0.00 — a positive-difference line
 * with no usable cost, the case excludeCostlessIncreases-equivalent logic must catch.
 */
class SmartStockAdjustmentImportRealFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'inventory.adjustments.create', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.adjustments.create');
        Sanctum::actingAs($user);
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
        $bpp = Warehouse::query()->create(['code' => 'BPP', 'name' => 'Balikpapan', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Unit']);
        $scPcc = Item::query()->create(['item_code' => 'SC PCC 50 KG', 'item_name' => 'SC PCC 50 KG', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'qty_category' => 'unit']);
        $cat = Item::query()->create(['item_code' => 'CAT', 'item_name' => 'CAT', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'qty_category' => 'unit']);

        return compact('bpp', 'itemGroup', 'uom', 'scPcc', 'cat');
    }

    public function test_only_items_with_a_real_difference_become_adjustment_lines_and_costless_increases_are_excluded_not_fatal(): void
    {
        $master = $this->seedMaster();
        // Live system balance (100,000) deliberately differs from the file's BALANCE (103,066) —
        // this is the line that should turn into a real Stock Adjustment. CAT is left unseeded
        // (system_qty 0) to exercise the "increase with no usable cost" exclusion.
        $this->seedStock($master['scPcc']->id, $master['bpp']->id, 100000, unitCost: 30000);

        $upload = $this->post('/api/v1/stock-adjustments/smart-import', [
            'file' => $this->fixtureFile('xlsStockBalance.xlsx'),
            'metadata_adjustment_date' => '2026-09-20',
        ]);
        $upload->assertCreated();

        $summary = $upload->json('data.preview_summary');

        $costMissingCodes = collect($summary['cost_missing_items'])->pluck('item_code')->all();
        $this->assertContains('CAT', $costMissingCodes);

        $bppGroup = collect($summary['groups'])->firstWhere('warehouse_code', 'BPP');
        $this->assertNotNull($bppGroup);
        $bppLineCodes = collect($bppGroup['lines'])->pluck('item_code')->all();
        $this->assertContains('SC PCC 50 KG', $bppLineCodes);
        $this->assertNotContains('CAT', $bppLineCodes, 'CAT must be excluded up front, not left in to fail the whole group at commit.');

        $line = collect($bppGroup['lines'])->firstWhere('item_code', 'SC PCC 50 KG');
        $this->assertEqualsWithDelta(100000.0, $line['system_qty'], 0.01);
        $this->assertEqualsWithDelta(103066.0, $line['qty'], 0.01);
        $this->assertEqualsWithDelta(3066.0, $line['difference_qty'], 0.01);

        $resolve = $this->post('/api/v1/stock-adjustments/smart-import/'.$upload->json('data.id').'/resolve');
        $resolve->assertOk();
        $result = $resolve->json('data.preview_summary');

        $this->assertSame(1, $result['documents_created']);
        $this->assertGreaterThan(0, $result['cost_missing_count']);
        $this->assertSame([], $result['failures']);

        $adjustment = StockAdjustment::query()->where('warehouse_id', $master['bpp']->id)->sole();
        $adjustedCodes = $adjustment->items->pluck('item_code')->all();
        $this->assertContains('SC PCC 50 KG', $adjustedCodes);
        $this->assertNotContains('CAT', $adjustedCodes);

        $adjustedLine = $adjustment->items->firstWhere('item_code', 'SC PCC 50 KG');
        $this->assertEqualsWithDelta(103066.0, (float) $adjustedLine->counted_qty, 0.01);
        $this->assertEqualsWithDelta(3066.0, (float) $adjustedLine->difference_qty, 0.01);
    }

    public function test_reimporting_after_reconciliation_is_idempotent(): void
    {
        $master = $this->seedMaster();
        // System already matches the file's BALANCE exactly -- nothing should move.
        $this->seedStock($master['scPcc']->id, $master['bpp']->id, 103066, unitCost: 30000);

        $upload = $this->post('/api/v1/stock-adjustments/smart-import', [
            'file' => $this->fixtureFile('xlsStockBalance.xlsx'),
            'metadata_adjustment_date' => '2026-09-20',
        ]);
        $upload->assertCreated();

        $bppGroup = collect($upload->json('data.preview_summary.groups'))->firstWhere('warehouse_code', 'BPP');
        $this->assertNotNull($bppGroup);
        $this->assertSame(0, $bppGroup['changed_line_count']);

        $resolve = $this->post('/api/v1/stock-adjustments/smart-import/'.$upload->json('data.id').'/resolve');
        $resolve->assertOk();
        $result = $resolve->json('data.preview_summary');

        $this->assertSame(0, $result['documents_created']);
        $this->assertGreaterThan(0, $result['unchanged_count']);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }
}
