<?php

namespace Tests\Feature\Import;

use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Import Wizard — UOMs module. No FK fields, mirrors ItemGroupImportTest's pipeline-reuse checks. */
class UomImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.uoms.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.uoms.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_happy_path_import_creates_uoms(): void
    {
        $csv = "Satuan,Simbol\nPcs,pcs\nZak,zak\n";

        $upload = $this->post('/api/v1/import/uoms/batches', ['file' => $this->csvFile('uoms.csv', $csv)]);
        $upload->assertCreated();
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['Satuan' => 'name', 'Simbol' => 'symbol'],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 2, 'warning' => 0, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame(2, UnitOfMeasurement::query()->count());
        $this->assertSame('zak', UnitOfMeasurement::query()->where('name', 'Zak')->first()->symbol);
    }

    /**
     * Regression: an empty column can fuzzy-match a field name by header text alone (e.g.
     * "Catatan" scoring high against "Name"'s synonyms). If auto-suggested, it would collide
     * with the real name column and blank every row's name — dropped columns must never be
     * auto-suggested for mapping, no matter how their header text scores.
     */
    public function test_dropped_columns_are_never_auto_suggested_for_mapping(): void
    {
        $csv = "Satuan,Simbol,Kategori,Catatan\nPcs,pcs,Aktif,\nZak,zak,Aktif,\n";

        $upload = $this->post('/api/v1/import/uoms/batches', ['file' => $this->csvFile('uoms.csv', $csv)]);
        $upload->assertCreated();

        $this->assertContains('Catatan', $upload->json('data.cleaning_report.dropped_empty_columns'));
        $this->assertContains('Kategori', $upload->json('data.cleaning_report.dropped_constant_columns'));
        $this->assertNull($upload->json('data.suggested_mapping.Catatan'));
        $this->assertNull($upload->json('data.suggested_mapping.Kategori'));
        $this->assertSame('name', $upload->json('data.suggested_mapping.Satuan'));
    }

    public function test_no_fk_fields_means_fk_candidates_is_empty(): void
    {
        $csv = "Satuan,Simbol\nPcs,pcs\n";
        $upload = $this->post('/api/v1/import/uoms/batches', ['file' => $this->csvFile('uoms.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['Satuan' => 'name', 'Simbol' => 'symbol'],
        ])->assertOk();

        $candidates = $this->getJson("/api/v1/import/batches/{$batchId}/fk-candidates");
        $candidates->assertOk();
        $this->assertSame([], $candidates->json('data'));
    }
}
