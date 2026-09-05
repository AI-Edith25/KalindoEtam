<?php

namespace Tests\Feature\Import;

use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import Wizard — Suppliers module. Exercises transformRow()'s Address1-4 -> _address
 * concatenation directly (mapping '_address' at a real request the same way the 1-step
 * auto flow does internally — see AutoImportTest for the auto-mapping-injection side of it).
 */
class SupplierImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.suppliers.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.suppliers.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_happy_path_import_concatenates_address_and_skips_blank_segments(): void
    {
        $csv = "CusCode,CusName,Tel,Email,Address1,Address2,Address3,Address4\n"
            ."SUP-001,PT Sample Supplier,0541-111,supplier@example.com,Jl. Sample,,Blok B,\n";

        $upload = $this->post('/api/v1/import/suppliers/batches', ['file' => $this->csvFile('suppliers.csv', $csv)]);
        $upload->assertCreated();
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => [
                'CusCode' => 'supplier_code',
                'CusName' => 'supplier_name',
                'Tel' => 'phone',
                'Email' => 'email',
                '_address' => 'address',
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 1, 'valid' => 1, 'warning' => 0, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $supplier = Supplier::query()->where('supplier_code', 'SUP-001')->first();
        $this->assertNotNull($supplier);
        $this->assertSame('Jl. Sample, Blok B', $supplier->address);
        $this->assertTrue($supplier->is_active);
    }

    public function test_missing_required_name_skips_only_that_row(): void
    {
        $csv = "CusCode,CusName,Tel,Email,Address1,Address2,Address3,Address4\n"
            ."SUP-001,,0541-111,,,,,\n"
            ."SUP-002,PT Two,,,,,,\n";

        $upload = $this->post('/api/v1/import/suppliers/batches', ['file' => $this->csvFile('suppliers.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['CusCode' => 'supplier_code', 'CusName' => 'supplier_name', 'Tel' => 'phone', 'Email' => 'email', '_address' => 'address'],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 1, 'warning' => 0, 'error' => 1], $preview->json('data.summary'));
    }
}
