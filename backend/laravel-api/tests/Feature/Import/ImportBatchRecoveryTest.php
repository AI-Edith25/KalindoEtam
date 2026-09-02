<?php

namespace Tests\Feature\Import;

use App\Enums\ImportBatchStatus;
use App\Jobs\ProcessImportBatchJob;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the two gaps a queue worker outage exposed: a batch that never gets
 * picked up has no terminal, explained state, and there was no way to run the
 * commit pipeline without a live worker.
 */
class ImportBatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ItemGroup::query()->create(['name' => 'General']);
        UnitOfMeasurement::query()->create(['name' => 'Pcs']);

        Permission::query()->firstOrCreate(['name' => 'master.items.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.items.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('items.csv', $content);
    }

    private function mapping(): array
    {
        return [
            'mapping' => [
                'Kode Barang' => 'item_code',
                'Nama Barang' => 'item_name',
                'Kategori' => 'item_group_id',
                'Satuan' => 'uom_id',
                'Harga' => 'standard_rate',
            ],
            'clean_settings' => ['standard_rate' => 'dot_thousands'],
        ];
    }

    public function test_job_failed_hook_marks_batch_failed_with_the_exception_message(): void
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga\nITM-900,Barang Uji,General,Pcs,10000\n";
        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile($csv)]);
        $batchId = $upload->json('data.batch.id');

        $job = new ProcessImportBatchJob($batchId);
        $job->failed(new RuntimeException('Simulated worker crash.'));

        $batch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame(ImportBatchStatus::FAILED, $batch->status);
        $this->assertSame('Simulated worker crash.', $batch->failure_reason);
    }

    public function test_process_batch_command_recovers_a_batch_stuck_at_queued(): void
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga\nITM-901,Barang Uji,General,Pcs,10000\n";
        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile($csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();
        $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();

        // Simulate the real-world symptom: queued (queued_at set) but never picked up by a
        // worker, so it never reaches PROCESSING/COMPLETED/FAILED on its own.
        $batch = ImportBatch::query()->findOrFail($batchId);
        $batch->update([
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
            'status' => ImportBatchStatus::QUEUED,
            'queued_at' => now()->subMinutes(30),
        ]);

        $this->artisan('import:process-batch', ['batchId' => $batchId])->assertExitCode(0);

        $batch->refresh();
        $this->assertSame(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertNotNull($batch->started_at);
        $this->assertSame(1, Item::query()->where('item_code', 'ITM-901')->count());
    }

    public function test_process_batch_command_marks_batch_failed_for_an_unknown_batch_id(): void
    {
        $this->artisan('import:process-batch', ['batchId' => (string) Str::uuid()])
            ->assertExitCode(1);
    }
}
