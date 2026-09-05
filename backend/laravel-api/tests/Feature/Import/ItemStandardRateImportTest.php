<?php

namespace Tests\Feature\Import;

use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import Wizard — Item Standard Rates module (Item Prices page, not the Items page).
 * Exercises transformRow()'s UnitPrice-falls-back-to-UnitCost coalescing, and
 * write_mode=update_only failing an unknown item_code instead of creating a bare Item.
 */
class ItemStandardRateImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.item_standard_rates.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.item_standard_rates.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function makeItem(string $code, float $rate = 0): Item
    {
        $group = ItemGroup::query()->firstOrCreate(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->firstOrCreate(['name' => 'Pcs']);

        return Item::query()->create([
            'item_code' => $code,
            'item_name' => "Item {$code}",
            'item_group_id' => $group->id,
            'uom_id' => $uom->id,
            'standard_rate' => $rate,
        ]);
    }

    public function test_unit_price_updates_standard_rate_when_present(): void
    {
        $this->makeItem('ITM-001', 0);

        $csv = "ItemCode,UnitPrice,UnitCost\nITM-001,65000,60000\n";

        $upload = $this->post('/api/v1/import/item-standard-rates/batches', ['file' => $this->csvFile('rates.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['ItemCode' => 'item_code', '_standard_rate' => 'standard_rate'],
        ])->assertOk();

        $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();
        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'update_only',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame('65000.00', Item::query()->where('item_code', 'ITM-001')->first()->standard_rate);
    }

    public function test_unit_cost_used_when_unit_price_is_zero(): void
    {
        $this->makeItem('ITM-002', 0);

        $csv = "ItemCode,UnitPrice,UnitCost\nITM-002,0,42000\n";

        $upload = $this->post('/api/v1/import/item-standard-rates/batches', ['file' => $this->csvFile('rates.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['ItemCode' => 'item_code', '_standard_rate' => 'standard_rate'],
        ])->assertOk();

        $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();
        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'update_only',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame('42000.00', Item::query()->where('item_code', 'ITM-002')->first()->standard_rate);
    }

    public function test_unknown_item_code_fails_the_row_instead_of_creating_an_item(): void
    {
        $csv = "ItemCode,UnitPrice,UnitCost\nDOES-NOT-EXIST,10000,0\n";

        $upload = $this->post('/api/v1/import/item-standard-rates/batches', ['file' => $this->csvFile('rates.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['ItemCode' => 'item_code', '_standard_rate' => 'standard_rate'],
        ])->assertOk();

        $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();
        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'update_only',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame(0, Item::query()->where('item_code', 'DOES-NOT-EXIST')->count());

        $failedRows = $this->get("/api/v1/import/batches/{$batchId}/failed-rows");
        $failedRows->assertOk();
        $this->assertStringContainsString('Not found (update-only mode).', $failedRows->streamedContent());
    }
}
