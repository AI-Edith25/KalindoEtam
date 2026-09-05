<?php

namespace Tests\Feature\Import;

use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Import Wizard — Items module end to end (upload -> mapping -> fk resolution -> preview -> commit). */
class ItemImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        ItemGroup::query()->create(['name' => 'General']);
        UnitOfMeasurement::query()->create(['name' => 'Pcs']);

        Permission::query()->firstOrCreate(['name' => 'master.items.import', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('master.items.import');
        Sanctum::actingAs($this->user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
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
                'Mata Uang' => null,
            ],
            'clean_settings' => ['standard_rate' => 'dot_thousands'],
        ];
    }

    public function test_happy_path_import_cleans_and_creates_items(): void
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga,Mata Uang\n"
            ."ITM-001,Semen Portland,General,Pcs,Rp 65.000,IDR\n"
            ."ITM-002,Besi Beton,General,Pcs,# 120.000,IDR\n";

        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile('items.csv', $csv)]);
        $upload->assertCreated();
        $this->assertContains('Mata Uang', $upload->json('data.cleaning_report.dropped_constant_columns'));
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $preview->assertOk();
        $this->assertSame(['total' => 2, 'valid' => 2, 'warning' => 0, 'error' => 0], $preview->json('data.summary'));

        $commit = $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ]);
        $commit->assertOk();

        $this->assertSame(2, Item::query()->count());
        $item = Item::query()->where('item_code', 'ITM-001')->first();
        $this->assertSame('Semen Portland', $item->item_name);
        $this->assertSame('65000.00', $item->standard_rate);
        $this->assertSame('General', $item->itemGroup->name);
        $this->assertSame('Pcs', $item->uom->name);

        $item2 = Item::query()->where('item_code', 'ITM-002')->first();
        $this->assertSame('120000.00', $item2->standard_rate);
    }

    public function test_skip_invalid_mode_commits_valid_rows_and_reports_failed_rows(): void
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga,Mata Uang\n"
            ."ITM-001,Semen Portland,General,Pcs,65000,IDR\n"
            .",Nama Kosong,General,Pcs,10000,IDR\n"; // missing item_code -> invalid

        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile('items.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 2, 'valid' => 1, 'warning' => 0, 'error' => 1], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $this->assertSame(1, Item::query()->count());

        $failedRows = $this->get("/api/v1/import/batches/{$batchId}/failed-rows");
        $failedRows->assertOk();
        $this->assertStringContainsString('Nama Kosong', $failedRows->streamedContent());
    }

    public function test_all_or_nothing_mode_refuses_commit_when_preview_has_failures(): void
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga,Mata Uang\n"
            .",Nama Kosong,General,Pcs,10000,IDR\n";

        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile('items.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();
        $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'all_or_nothing',
        ])->assertStatus(422);

        $this->assertSame(0, Item::query()->count());
    }

    public function test_recommitting_the_same_file_upserts_instead_of_duplicating(): void
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga,Mata Uang\n"
            ."ITM-001,Semen Portland,General,Pcs,65000,IDR\n";

        foreach (range(1, 2) as $attempt) {
            $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile('items.csv', $csv)]);
            $batchId = $upload->json('data.batch.id');

            $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();
            $this->postJson("/api/v1/import/batches/{$batchId}/preview")->assertOk();
            $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
                'write_mode' => 'upsert',
                'commit_mode' => 'skip_invalid',
            ])->assertOk();
        }

        $this->assertSame(1, Item::query()->count());
    }

    public function test_unmatched_fk_value_can_be_resolved_by_auto_creating_the_master(): void
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga,Mata Uang\n"
            ."ITM-003,Cat Tembok,Grosir,Pcs,50000,IDR\n";

        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile('items.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $candidates = $this->getJson("/api/v1/import/batches/{$batchId}/fk-candidates");
        $candidates->assertOk();
        $this->assertSame('no_match', $candidates->json('data.item_group_id.Grosir.status'));

        $this->patchJson("/api/v1/import/batches/{$batchId}/fk-resolutions", [
            'resolutions' => ['item_group_id' => ['Grosir' => ['action' => 'create']]],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 1, 'valid' => 0, 'warning' => 1, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $group = ItemGroup::query()->where('name', 'Grosir')->first();
        $this->assertNotNull($group);
        $this->assertSame($group->id, Item::query()->where('item_code', 'ITM-003')->first()->item_group_id);
    }

    public function test_purchase_tax_code_resolves_by_code_and_unmatched_sales_tax_only_warns(): void
    {
        Tax::query()->create(['code' => 'PPN11', 'name' => 'PPN 11%', 'type' => TaxType::VAT, 'transaction_type' => TaxTransactionType::PURCHASE, 'rate' => 11, 'is_active' => true]);

        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga,Pajak Beli,Pajak Jual\n"
            ."ITM-004,Pipa PVC,General,Pcs,15000,PPN11,DOES-NOT-EXIST\n";

        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile('items.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", [
            'mapping' => [
                'Kode Barang' => 'item_code',
                'Nama Barang' => 'item_name',
                'Kategori' => 'item_group_id',
                'Satuan' => 'uom_id',
                'Harga' => 'standard_rate',
                'Pajak Beli' => 'purchase_tax_id',
                'Pajak Jual' => 'sales_tax_id',
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 1, 'valid' => 0, 'warning' => 1, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $item = Item::query()->where('item_code', 'ITM-004')->first();
        $this->assertNotNull($item);
        $this->assertSame('PPN11', $item->purchaseTax->code);
        $this->assertNull($item->sales_tax_id);
    }

    public function test_forbidden_without_import_permission(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga,Mata Uang\nITM-001,Semen,General,Pcs,1000,IDR\n";

        $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile('items.csv', $csv)])
            ->assertForbidden();
    }

    /** Regression: batch-scoped routes stay gated after moving the permission check into ImportController (Phase D). */
    public function test_forbidden_without_import_permission_on_batch_scoped_route(): void
    {
        $csv = "Kode Barang,Nama Barang,Kategori,Satuan,Harga,Mata Uang\nITM-001,Semen,General,Pcs,1000,IDR\n";
        $upload = $this->post('/api/v1/import/items/batches', ['file' => $this->csvFile('items.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->getJson("/api/v1/import/batches/{$batchId}")->assertForbidden();
    }
}
