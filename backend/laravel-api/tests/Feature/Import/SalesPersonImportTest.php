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

    /**
     * The real xlsSalesPersonListing.xlsx shape (title/company preamble rows, header on row
     * 5, most optional columns blank) used to make the 1-step /auto endpoint wrongly reject
     * with "Kolom wajib tidak dikenali: Code, Name" — HeaderDetector's structural heuristic
     * picked a later data row over the real header (see HeaderDetectorTest). The semantic
     * pass added to HeaderDetector fixes this without the user reformatting their export.
     */
    public function test_auto_import_recognizes_the_real_export_shape_with_preamble_rows(): void
    {
        $csv = "SALES PERSON LISTING,,,,,,,,\n"
            .",,,,,,,,\n"
            ."PT. KALINDO ETAM,,,,05/09/2026 23:02:40,,,,\n"
            .",,,,,,,,\n"
            ."Sales Person Code,Sales Person Name,Address,Area,Telephone,Fax,Ratio,Branch,Status\n"
            ."KE-0003,EDDY WIJAYA HOLIM,,,,,0,,Active\n"
            ."KE-AKHSAN,AKHSAN,,SMR,,,0,SMD,Active\n"
            ."KE-ANDRI,EKO ANDRI ASTUTI,,,,,0,,Active\n"
            ."KE-ANTONY,ANTONY,,,,,0,,Active\n"
            ."KE-AVRIANUS,AVRIANUS,,,,,0,,Active\n"
            ."KE-BUDI,BUDI SANTOSO,,,,,0,,Active\n"
            ."KE-CITRA,CITRA DEWI,,,,,0,,Active\n";

        $response = $this->post('/api/v1/import/sales-persons/auto', ['file' => $this->csvFile('sp.csv', $csv)]);

        $response->assertCreated();
        $this->assertSame('KE-0003', SalesPerson::query()->where('code', 'KE-0003')->first()?->code);
        $this->assertTrue(SalesPerson::query()->where('code', 'KE-AKHSAN')->first()?->is_active);
    }
}
