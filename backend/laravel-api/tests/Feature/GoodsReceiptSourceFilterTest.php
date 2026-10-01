<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Purchase > Goods Receipts list: source filter + source/imported_at in the list response. */
class GoodsReceiptSourceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Permission::query()->firstOrCreate(['name' => 'purchase.goods_receipts.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('purchase.goods_receipts.view');
        Sanctum::actingAs($viewer);
    }

    private function makeGoodsReceipt(string $source, ?string $documentSuffix = null): GoodsReceipt
    {
        $warehouse = Warehouse::query()->create(['name' => 'Main WH '.uniqid(), 'code' => 'WH-'.uniqid(), 'warehouse_type' => 'main']);
        $supplier = Supplier::query()->create(['supplier_code' => 'S-'.uniqid(), 'supplier_name' => 'Supplier '.uniqid()]);

        return GoodsReceipt::query()->create([
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'source' => $source,
            'source_document_number' => $documentSuffix,
        ]);
    }

    public function test_source_filter_returns_only_matching_goods_receipts(): void
    {
        $this->makeGoodsReceipt('manual');
        $imported = $this->makeGoodsReceipt('import', 'GRN/KE/00001/09/2026');

        $response = $this->getJson('/api/v1/goods-receipts?source=import');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$imported->id], $ids->all());
    }

    public function test_list_response_includes_source(): void
    {
        $this->makeGoodsReceipt('import', 'GRN/KE/00002/09/2026');

        $response = $this->getJson('/api/v1/goods-receipts');

        $response->assertOk();
        $this->assertSame('import', $response->json('data.0.source'));
    }
}
