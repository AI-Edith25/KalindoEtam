<?php

namespace Tests\Feature\Import;

use App\Models\Permission;
use App\Models\SalesPerson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Import Wizard — Sales Persons module. is_active is boolean, transformRow() maps legacy "Active"/"Inactive" text to it. */
class SalesPersonImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.sales_persons.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.sales_persons.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function mapping(): array
    {
        return [
            'mapping' => [
                'Sales Person Code' => 'code',
                'Sales Person Name' => 'name',
                'Telephone' => 'phone',
                '_is_active' => 'is_active',
            ],
        ];
    }

    public function test_happy_path_maps_active_and_inactive_status(): void
    {
        $csv = "Sales Person Code,Sales Person Name,Telephone,Status\n"
            ."SP-001,Budi Santoso,0541-111,Active\n"
            ."SP-002,Siti Aminah,0541-222,Inactive\n";

        $upload = $this->post('/api/v1/import/sales-persons/batches', ['file' => $this->csvFile('sp.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 2, 'warning' => 0, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertTrue(SalesPerson::query()->where('code', 'SP-001')->first()->is_active);
        $this->assertFalse(SalesPerson::query()->where('code', 'SP-002')->first()->is_active);
    }

    public function test_unrecognized_status_defaults_to_active(): void
    {
        $csv = "Sales Person Code,Sales Person Name,Telephone,Status\nSP-003,Ahmad,,\n";

        $upload = $this->post('/api/v1/import/sales-persons/batches', ['file' => $this->csvFile('sp.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();
        $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();
        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertTrue(SalesPerson::query()->where('code', 'SP-003')->first()->is_active);
    }

    public function test_missing_required_name_skips_only_that_row(): void
    {
        $csv = "Sales Person Code,Sales Person Name,Telephone,Status\n"
            .",No Name,,Active\n"
            ."SP-005,Has Name,,Active\n";

        $upload = $this->post('/api/v1/import/sales-persons/batches', ['file' => $this->csvFile('sp.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 1, 'warning' => 0, 'error' => 1], $preview->json('data.summary'));
    }
}
