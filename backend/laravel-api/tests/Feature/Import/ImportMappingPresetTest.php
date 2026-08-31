<?php

namespace Tests\Feature\Import;

use App\Models\ImportMappingPreset;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Import Wizard — saving/reusing/deleting a named column-mapping preset, scoped per module and gated by master.{module}.import. */
class ImportMappingPresetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        ItemGroup::query()->create(['name' => 'General']);
        UnitOfMeasurement::query()->create(['name' => 'Pcs']);

        Permission::query()->firstOrCreate(['name' => 'master.items.import', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'master.uoms.import', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['master.items.import', 'master.uoms.import']);
        Sanctum::actingAs($this->user);
    }

    private function completedItemsBatchId(): string
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga\nITM-001,Semen,General,Pcs,65000\n";
        $upload = $this->post('/api/v1/import/items/batches', ['file' => UploadedFile::fake()->createWithContent('items.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => [
                'Kode Barang' => 'item_code',
                'Nama Barang' => 'item_name',
                'Kategori' => 'item_group_id',
                'Satuan' => 'uom_id',
                'Harga' => 'standard_rate',
            ],
            'clean_settings' => ['standard_rate' => 'dot_thousands'],
        ])->assertOk();

        return $batchId;
    }

    public function test_a_completed_mapping_can_be_saved_and_listed_as_a_preset(): void
    {
        $batchId = $this->completedItemsBatchId();

        $this->postJson("/api/v1/import/batches/{$batchId}/mapping-presets", ['name' => 'Legacy Export'])
            ->assertCreated();

        $list = $this->getJson('/api/v1/import/items/mapping-presets');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('Legacy Export', $list->json('data.0.name'));
        $this->assertSame('item_code', $list->json('data.0.mapping.Kode Barang'));
    }

    public function test_presets_are_scoped_per_module(): void
    {
        $batchId = $this->completedItemsBatchId();
        $this->postJson("/api/v1/import/batches/{$batchId}/mapping-presets", ['name' => 'Legacy Export'])->assertCreated();

        $uomPresets = $this->getJson('/api/v1/import/uoms/mapping-presets');
        $uomPresets->assertOk();
        $this->assertCount(0, $uomPresets->json('data'));
    }

    public function test_a_duplicate_preset_name_within_the_same_module_is_rejected(): void
    {
        $batchId = $this->completedItemsBatchId();
        $this->postJson("/api/v1/import/batches/{$batchId}/mapping-presets", ['name' => 'Legacy Export'])->assertCreated();

        $secondBatchId = $this->completedItemsBatchId();
        $this->postJson("/api/v1/import/batches/{$secondBatchId}/mapping-presets", ['name' => 'Legacy Export'])
            ->assertUnprocessable();
    }

    public function test_a_preset_can_be_deleted(): void
    {
        $batchId = $this->completedItemsBatchId();
        $this->postJson("/api/v1/import/batches/{$batchId}/mapping-presets", ['name' => 'Legacy Export'])->assertCreated();
        $preset = ImportMappingPreset::query()->firstOrFail();

        $this->deleteJson("/api/v1/import/mapping-presets/{$preset->id}")->assertOk();

        $this->assertSame(0, ImportMappingPreset::query()->count());
    }

    public function test_forbidden_without_import_permission_on_preset_routes(): void
    {
        $batchId = $this->completedItemsBatchId();

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->postJson("/api/v1/import/batches/{$batchId}/mapping-presets", ['name' => 'Legacy Export'])->assertForbidden();
        $this->getJson('/api/v1/import/items/mapping-presets')->assertForbidden();
    }
}
