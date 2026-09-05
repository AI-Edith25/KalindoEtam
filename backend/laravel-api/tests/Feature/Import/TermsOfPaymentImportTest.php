<?php

namespace Tests\Feature\Import;

use App\Models\Permission;
use App\Models\TermsOfPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Import Wizard — Terms of Payment module. No FK fields, mirrors UomImportTest's pipeline-reuse checks. */
class TermsOfPaymentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.terms_of_payments.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.terms_of_payments.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_happy_path_import_creates_terms(): void
    {
        $csv = "Term Code,Term Description,Day\nCOD,Cash On Delivery,0\nN30,Net 30 Days,30\n";

        $upload = $this->post('/api/v1/import/terms-of-payments/batches', ['file' => $this->csvFile('terms.csv', $csv)]);
        $upload->assertCreated();
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['Term Code' => 'code', 'Term Description' => 'name', 'Day' => 'days'],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 2, 'warning' => 0, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame(2, TermsOfPayment::query()->count());
        $n30 = TermsOfPayment::query()->where('code', 'N30')->first();
        $this->assertSame('Net 30 Days', $n30->name);
        $this->assertSame(30, $n30->days);
        $this->assertTrue($n30->is_active);
    }

    public function test_missing_required_days_skips_only_that_row(): void
    {
        $csv = "Term Code,Term Description,Day\nCOD,Cash On Delivery,0\nBAD,No Days,\n";

        $upload = $this->post('/api/v1/import/terms-of-payments/batches', ['file' => $this->csvFile('terms.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => ['Term Code' => 'code', 'Term Description' => 'name', 'Day' => 'days'],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 1, 'warning' => 0, 'error' => 1], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame(1, TermsOfPayment::query()->count());
        $this->assertSame('COD', TermsOfPayment::query()->first()->code);
    }
}
