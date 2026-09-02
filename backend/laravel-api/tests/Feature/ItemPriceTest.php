<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ItemPrice;
use App\Models\Permission;
use App\Models\PriceZone;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Item Price per Zone: override CRUD, the "one active price per item per zone" constraint,
 * export/import round-trip, and the ItemController::index price_zone_id integration Sales Order
 * relies on for auto-filling the right rate.
 */
class ItemPriceTest extends TestCase
{
    use RefreshDatabase;

    protected Item $item;

    protected PriceZone $zoneA;

    protected PriceZone $zoneB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view', 'create', 'update', 'delete', 'import'] as $action) {
            Permission::query()->firstOrCreate(['name' => "master.item_prices.{$action}", 'guard_name' => 'web']);
        }
        Permission::query()->firstOrCreate(['name' => 'master.items.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo([
            'master.item_prices.view', 'master.item_prices.create', 'master.item_prices.update',
            'master.item_prices.delete', 'master.item_prices.import', 'master.items.view',
        ]);
        Sanctum::actingAs($user);

        $itemGroup = ItemGroup::query()->create(['name' => 'Semen']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Sak']);
        $this->item = Item::query()->create([
            'item_code' => 'SMN-001', 'item_name' => 'Semen Portland 50kg',
            'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 65000,
        ]);
        $this->zoneA = PriceZone::query()->create(['name' => 'Samarinda']);
        $this->zoneB = PriceZone::query()->create(['name' => 'Balikpapan']);
    }

    public function test_create_price_override(): void
    {
        $response = $this->postJson('/api/v1/item-prices', [
            'item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('item_prices', ['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);
    }

    public function test_duplicate_item_and_zone_is_rejected(): void
    {
        ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);

        $response = $this->postJson('/api/v1/item-prices', [
            'item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 72000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('item_id');
        $this->assertSame(1, ItemPrice::query()->where('item_id', $this->item->id)->where('price_zone_id', $this->zoneB->id)->count());
    }

    public function test_same_item_different_zone_is_allowed(): void
    {
        ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneA->id, 'rate' => 65000]);

        $response = $this->postJson('/api/v1/item-prices', [
            'item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000,
        ]);

        $response->assertCreated();
    }

    public function test_update_rate(): void
    {
        $override = ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);

        $response = $this->putJson("/api/v1/item-prices/{$override->id}", ['rate' => 72000]);

        $response->assertOk();
        $this->assertDatabaseHas('item_prices', ['id' => $override->id, 'rate' => 72000]);
    }

    public function test_delete_price_override(): void
    {
        $override = ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);

        $this->deleteJson("/api/v1/item-prices/{$override->id}")->assertOk();
        $this->assertDatabaseMissing('item_prices', ['id' => $override->id]);
    }

    public function test_price_change_is_recorded_to_audit_log(): void
    {
        $override = ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);

        $this->putJson("/api/v1/item-prices/{$override->id}", ['rate' => 72000])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'price_changed', 'module' => 'item_prices']);
    }

    public function test_export_returns_csv_of_existing_overrides(): void
    {
        ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);

        $response = $this->get('/api/v1/item-prices/export');

        $response->assertOk();
        $this->assertStringContainsString('SMN-001', $response->streamedContent());
        $this->assertStringContainsString('Balikpapan', $response->streamedContent());
        $this->assertStringContainsString('70000', $response->streamedContent());
    }

    public function test_import_creates_and_updates_overrides_and_reports_skipped_rows(): void
    {
        ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneA->id, 'rate' => 60000]);

        $csv = "item_code,zone_name,rate\n"
            ."SMN-001,Samarinda,65000\n" // update existing
            ."SMN-001,Balikpapan,70000\n" // create new
            ."NOPE-999,Samarinda,1000\n" // unknown item -> skipped
            ."SMN-001,Nowhere,1000\n"; // unknown zone -> skipped

        $file = UploadedFile::fake()->createWithContent('prices.csv', $csv);
        $response = $this->postJson('/api/v1/item-prices/import', ['file' => $file]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.created'));
        $this->assertSame(1, $response->json('data.updated'));
        $this->assertCount(2, $response->json('data.skipped'));

        $this->assertDatabaseHas('item_prices', ['item_id' => $this->item->id, 'price_zone_id' => $this->zoneA->id, 'rate' => 65000]);
        $this->assertDatabaseHas('item_prices', ['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);
    }

    public function test_items_index_without_price_zone_uses_standard_rate_as_effective_rate(): void
    {
        ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);

        $response = $this->getJson('/api/v1/items');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $this->item->id);
        $this->assertSame('65000.00', $row['standard_rate']);
        $this->assertSame('65000.00', $row['effective_rate']);
    }

    public function test_items_index_with_price_zone_returns_the_zone_override_as_effective_rate(): void
    {
        ItemPrice::query()->create(['item_id' => $this->item->id, 'price_zone_id' => $this->zoneB->id, 'rate' => 70000]);

        $response = $this->getJson("/api/v1/items?price_zone_id={$this->zoneB->id}");

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $this->item->id);
        $this->assertSame('65000.00', $row['standard_rate']);
        $this->assertSame('70000.00', $row['effective_rate']);
    }

    public function test_items_index_with_price_zone_but_no_override_falls_back_to_standard_rate(): void
    {
        $response = $this->getJson("/api/v1/items?price_zone_id={$this->zoneA->id}");

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $this->item->id);
        $this->assertSame('65000.00', $row['effective_rate']);
    }
}
