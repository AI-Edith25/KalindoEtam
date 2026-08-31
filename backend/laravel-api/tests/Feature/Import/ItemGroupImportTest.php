<?php

namespace Tests\Feature\Import;

use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Import Wizard — Item Groups module. No FK fields, so this also covers the "nothing to resolve" pipeline path. */
class ItemGroupImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.item_groups.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.item_groups.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_happy_path_import_creates_item_groups(): void
    {
        $csv = "Nama,Deskripsi\nGeneral,General purpose\nGrosir,Wholesale\n";

        $upload = $this->post('/api/v1/import/item-groups/batches', ['file' => $this->csvFile('groups.csv', $csv)]);
        $upload->assertCreated();
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['Nama' => 'name', 'Deskripsi' => 'description'],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 2, 'warning' => 0, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame(2, ItemGroup::query()->count());
        $this->assertSame('Wholesale', ItemGroup::query()->where('name', 'Grosir')->first()->description);
    }

    public function test_no_fk_fields_means_fk_candidates_is_empty(): void
    {
        $csv = "Nama,Deskripsi\nGeneral,General purpose\n";
        $upload = $this->post('/api/v1/import/item-groups/batches', ['file' => $this->csvFile('groups.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['Nama' => 'name', 'Deskripsi' => 'description'],
        ])->assertOk();

        $candidates = $this->getJson("/api/v1/import/batches/{$batchId}/fk-candidates");
        $candidates->assertOk();
        $this->assertSame([], $candidates->json('data'));
    }

    public function test_recommitting_the_same_file_upserts_instead_of_duplicating(): void
    {
        $csv = "Nama,Deskripsi\nGeneral,General purpose\n";

        foreach (range(1, 2) as $attempt) {
            $upload = $this->post('/api/v1/import/item-groups/batches', ['file' => $this->csvFile('groups.csv', $csv)]);
            $batchId = $upload->json('data.batch.id');

            $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
                'mapping' => ['Nama' => 'name', 'Deskripsi' => 'description'],
            ])->assertOk();
            $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();
            $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
                'write_mode' => 'upsert',
                'commit_mode' => 'skip_invalid',
            ])->assertOk();
        }

        $this->assertSame(1, ItemGroup::query()->count());
    }
}
