<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ItemWarehousePrice;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-Warehouse Pricing: the bulk-cell endpoint (create/update/delete in one batch, atomic,
 * one audit entry), export/import round-trip, "Sync to Main WH" live resolution (see
 * ItemPriceResolver), and the bulk sync-flag toggle. Reuses master.item_prices.* permissions,
 * same as ItemPriceTest for Price Zone.
 */
class ItemWarehousePriceTest extends TestCase
{
    use RefreshDatabase;

    protected Item $item;

    protected Warehouse $main;

    protected Warehouse $branch;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view', 'create', 'update', 'delete', 'import'] as $action) {
            Permission::query()->firstOrCreate(['name' => "master.item_prices.{$action}", 'guard_name' => 'web']);
        }
        Permission::query()->firstOrCreate(['name' => 'master.items.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'master.warehouses.create', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo([
            'master.item_prices.view', 'master.item_prices.create', 'master.item_prices.update',
            'master.item_prices.delete', 'master.item_prices.import', 'master.items.view', 'master.warehouses.create',
        ]);
        Sanctum::actingAs($user);

        $itemGroup = ItemGroup::query()->create(['name' => 'Semen']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Sak']);
        $this->item = Item::query()->create([
            'item_code' => 'SMN-001', 'item_name' => 'Semen Portland 50kg',
            'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 65000,
        ]);
        $this->main = Warehouse::query()->create(['name' => 'Balikpapan', 'code' => 'BPP', 'warehouse_type' => WarehouseType::MAIN]);
        $this->branch = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::TRANSIT]);
    }

    public function test_bulk_update_creates_updates_and_deletes_cells_in_one_batch(): void
    {
        $existing = ItemWarehousePrice::query()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->branch->id, 'rate' => 70000]);

        $response = $this->postJson('/api/v1/item-warehouse-prices/bulk', [
            'cells' => [
                ['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 68000], // create
                ['item_id' => $this->item->id, 'warehouse_id' => $this->branch->id, 'rate' => null], // delete
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('item_warehouse_prices', ['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 68000]);
        $this->assertDatabaseMissing('item_warehouse_prices', ['id' => $existing->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'warehouse_price_changed', 'module' => 'item_warehouse_prices']);
    }

    public function test_bulk_update_rejects_duplicate_cell_in_same_batch(): void
    {
        $response = $this->postJson('/api/v1/item-warehouse-prices/bulk', [
            'cells' => [
                ['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 68000],
                ['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 69000],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('item_warehouse_prices', ['item_id' => $this->item->id, 'warehouse_id' => $this->main->id]);
    }

    public function test_bulk_update_rejects_negative_rate(): void
    {
        $response = $this->postJson('/api/v1/item-warehouse-prices/bulk', [
            'cells' => [['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => -5]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cells.0.rate');
    }

    public function test_export_returns_wide_csv_with_one_column_per_warehouse(): void
    {
        ItemWarehousePrice::query()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 68000]);

        $response = $this->get('/api/v1/item-warehouse-prices/export');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('item_code,item_name,standard_rate,BPP,SMD,sync_to_main_wh', $content);
        $this->assertStringContainsString('SMN-001', $content);
        $this->assertStringContainsString('68000', $content);
    }

    public function test_import_preview_reports_changes_without_writing(): void
    {
        $csv = "item_code,item_name,standard_rate,BPP,SMD,sync_to_main_wh\n"
            ."SMN-001,Semen Portland 50kg,65000,68000,,0\n";

        $file = UploadedFile::fake()->createWithContent('wh-prices.csv', $csv);
        $response = $this->postJson('/api/v1/item-warehouse-prices/import-preview', ['file' => $file]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.to_create'));
        $this->assertDatabaseMissing('item_warehouse_prices', ['item_id' => $this->item->id, 'warehouse_id' => $this->main->id]);
    }

    public function test_import_commit_applies_cell_and_sync_flag_changes_in_one_transaction(): void
    {
        $csv = "item_code,item_name,standard_rate,BPP,SMD,sync_to_main_wh\n"
            ."SMN-001,Semen Portland 50kg,65000,68000,,1\n";

        $file = UploadedFile::fake()->createWithContent('wh-prices.csv', $csv);
        $response = $this->postJson('/api/v1/item-warehouse-prices/import-commit', ['file' => $file]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.cells_applied'));
        $this->assertSame(1, $response->json('data.sync_changes'));
        $this->assertDatabaseHas('item_warehouse_prices', ['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 68000]);
        $this->assertTrue($this->item->fresh()->sync_to_main_wh);
    }

    public function test_import_reports_unresolvable_item_code_as_error(): void
    {
        $csv = "item_code,item_name,standard_rate,BPP,SMD,sync_to_main_wh\n"
            ."NOPE-999,Unknown,0,68000,,0\n";

        $file = UploadedFile::fake()->createWithContent('wh-prices.csv', $csv);
        $response = $this->postJson('/api/v1/item-warehouse-prices/import-preview', ['file' => $file]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.errors'));
    }

    public function test_items_index_resolves_warehouse_override_before_standard_rate(): void
    {
        ItemWarehousePrice::query()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 68000]);

        $response = $this->getJson("/api/v1/items?warehouse_id={$this->main->id}");

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $this->item->id);
        $this->assertSame('68000.00', $row['effective_rate']);
    }

    public function test_items_index_with_no_warehouse_override_falls_back_to_standard_rate(): void
    {
        $response = $this->getJson("/api/v1/items?warehouse_id={$this->branch->id}");

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $this->item->id);
        $this->assertSame('65000.00', $row['effective_rate']);
    }

    public function test_sync_to_main_wh_resolves_branch_warehouse_to_mains_price_live(): void
    {
        ItemWarehousePrice::query()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 68000]);
        // A stale branch override exists but must be ignored while the sync flag is on.
        ItemWarehousePrice::query()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->branch->id, 'rate' => 50000]);
        $this->item->update(['sync_to_main_wh' => true]);

        $response = $this->getJson("/api/v1/items?warehouse_id={$this->branch->id}");

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $this->item->id);
        $this->assertSame('68000.00', $row['effective_rate']);
    }

    public function test_bulk_sync_to_main_wh_toggles_flag_and_records_audit_log(): void
    {
        $response = $this->postJson('/api/v1/items/bulk-sync-to-main-wh', ['item_ids' => [$this->item->id], 'value' => true]);

        $response->assertOk();
        $this->assertTrue($this->item->fresh()->sync_to_main_wh);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sync_to_main_wh_changed', 'module' => 'item']);
    }
}
