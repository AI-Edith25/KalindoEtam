<?php

namespace Tests\Feature\Import;

use App\Enums\WarehouseType;
use App\Models\Permission;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import Wizard — Warehouses (Area) module. warehouse_type has no DB default, so the
 * manual-wizard path (unlike the 1-step auto flow — see AutoImportTest) must be given
 * it via field_defaults, exactly like a user would when mapping this file by hand.
 */
class WarehouseImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.warehouses.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.warehouses.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_happy_path_import_creates_warehouses_with_field_default_type(): void
    {
        $csv = "Code,Description\nBPP,Balikpapan\nSMD,Samarinda\n";

        $upload = $this->post('/api/v1/import/warehouses/batches', ['file' => $this->csvFile('branches.csv', $csv)]);
        $upload->assertCreated();
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['Code' => 'code', 'Description' => 'name'],
            'field_defaults' => ['warehouse_type' => 'transit'],
        ])->assertOk();

        $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame(2, Warehouse::query()->count());
        $bpp = Warehouse::query()->where('code', 'BPP')->first();
        $this->assertSame('Balikpapan', $bpp->name);
        $this->assertSame(WarehouseType::TRANSIT, $bpp->warehouse_type);
    }

    public function test_missing_required_code_skips_only_that_row(): void
    {
        $csv = "Code,Description\n,No Code\nSMD,Samarinda\n";

        $upload = $this->post('/api/v1/import/warehouses/batches', ['file' => $this->csvFile('branches.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['Code' => 'code', 'Description' => 'name'],
            'field_defaults' => ['warehouse_type' => 'transit'],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 1, 'warning' => 0, 'error' => 1], $preview->json('data.summary'));
    }
}
