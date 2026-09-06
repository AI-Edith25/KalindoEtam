<?php

namespace Tests\Feature\Import;

use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\FifoLayer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\OpeningStock;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FifoLayerService;
use App\Services\OpeningStockService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Opening Stock's import is the one 1-step template that creates a whole document per row
 * (CreatesRelatedRecords), not a single flat record — see OpeningStockImportTemplate and
 * ProcessImportBatchJob. Every row lands as a Draft tagged with the same ImportBatch id, and
 * Submit All/Cancel All act on the whole batch together.
 */
class OpeningStockImportTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected Item $item;

    protected Item $item2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'inventory.opening_stock.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.opening_stock.import');
        Sanctum::actingAs($user);

        $this->warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);
        $this->item2 = Item::query()->create([
            'item_code' => 'ITM002', 'item_name' => 'Besi Beton 10mm', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 90000,
        ]);
    }

    private function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('opening.csv', $content);
    }

    public function test_auto_import_creates_one_draft_document_per_row_tagged_with_the_same_batch(): void
    {
        $csv = "Item Code,Warehouse,Date,Qty,Unit Cost\n"
            ."ITM001,SMD,2026-01-01,100,58000\n"
            ."ITM002,SMD,2026-01-01,50,85000\n";

        $response = $this->post('/api/v1/import/opening-stock/auto', ['file' => $this->csvFile($csv)]);
        $response->assertCreated();

        $documents = OpeningStock::query()->get();
        $this->assertCount(2, $documents);
        $this->assertTrue($documents->every(fn (OpeningStock $doc) => $doc->status->value === 'draft'));
        $this->assertSame(1, $documents->pluck('import_batch_id')->unique()->count());
        $this->assertNotNull($documents->first()->import_batch_id);
    }

    public function test_submit_batch_creates_layers_for_every_document_in_the_batch(): void
    {
        $csv = "Item Code,Warehouse,Date,Qty,Unit Cost\n"
            ."ITM001,SMD,2026-01-01,100,58000\n"
            ."ITM002,SMD,2026-01-01,50,85000\n";
        $this->post('/api/v1/import/opening-stock/auto', ['file' => $this->csvFile($csv)]);

        $batchId = OpeningStock::query()->first()->import_batch_id;
        $documents = app(OpeningStockService::class)->submitBatch($batchId);

        $this->assertCount(2, $documents);
        $this->assertTrue($documents->every(fn (OpeningStock $doc) => $doc->status->value === 'submitted'));
        $this->assertSame(2, FifoLayer::query()->where('source_type', StockVoucherType::OPENING_STOCK->value)->count());
    }

    public function test_cancel_batch_is_rejected_and_names_the_blocking_document_if_any_layer_is_consumed(): void
    {
        $csv = "Item Code,Warehouse,Date,Qty,Unit Cost\n"
            ."ITM001,SMD,2026-01-01,100,58000\n"
            ."ITM002,SMD,2026-01-01,50,85000\n";
        $this->post('/api/v1/import/opening-stock/auto', ['file' => $this->csvFile($csv)]);

        $batchId = OpeningStock::query()->first()->import_batch_id;
        app(OpeningStockService::class)->submitBatch($batchId);

        // Consume part of ITM001's layer, simulating a Delivery that happened after submit.
        app(FifoLayerService::class)->consume($this->item->id, $this->warehouse->id, 10, StockVoucherType::DELIVERY, (string) Str::uuid());

        $blockingDocument = OpeningStock::query()->where('import_batch_id', $batchId)->whereHas(
            'items', fn ($q) => $q->where('item_id', $this->item->id),
        )->firstOrFail();

        try {
            app(OpeningStockService::class)->cancelBatch($batchId);
            $this->fail('Expected cancelBatch() to reject a batch with a consumed layer.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString($blockingDocument->document_number, $e->getMessage());
        }

        // Nothing was cancelled — all-or-nothing.
        $this->assertSame(2, OpeningStock::query()->where('status', 'submitted')->count());
    }
}
