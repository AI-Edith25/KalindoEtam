<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exercises SalesInvoiceHistoryImportController's HTTP contract — same "always previewed first,
 * resolve() is the universal confirm-and-queue step" ticket requirement as
 * PurchaseHistoryImportControllerTest.
 */
class SalesInvoiceHistoryImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Permission::query()->firstOrCreate(['name' => 'sales.invoices.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.invoices.import');
        Sanctum::actingAs($user);

        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak', 'symbol' => 'ZAK']);
        Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Test Item', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
    }

    private function csv(array $rows): UploadedFile
    {
        $content = implode("\r\n", array_map(
            fn (array $row) => implode(',', array_map(fn ($v) => $v ?? '', $row)),
            $rows
        ))."\r\n";

        return UploadedFile::fake()->createWithContent('test.csv', $content);
    }

    private function validFile(): UploadedFile
    {
        return $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['31/08/2026 - 30/09/2026 - Base Currency'],
            ['', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['PT. KALINDO ETAM', '', '', '30/09/2026 15:33:31', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['DATE', 'DOCUMENT #', 'CUSTOMER#', 'NAME', '', 'DELIVERY TO', '', 'DISC', 'TAX', 'T.CODE', 'AMOUNT', 'REFERENCE 1 #', 'REFERENCE 2 #'],
            ['ITEM # ', '', 'DESCRIPTION', '', 'UOM', 'QUANTITY', 'UNIT PRICE', 'DISC', 'TAX', 'T.CODE', 'LINE AMOUNT', '', ''],
            ['30/09/2026', 'SI/KE/00001/09/2026', 'CUST1', 'Test Customer', '', '', '', 0, 11000, '', 111000, 'SO/KE/1', ''],
            ['ITEM1', '', 'Test Item', '', 'ZAK', 10, 10000, 0, 11000, 'PPN-K11(EXC)', 111000, '', ''],
        ]);
    }

    public function test_store_always_leaves_the_batch_previewed_with_a_summary_even_when_nothing_needs_resolving(): void
    {
        $response = $this->postJson('/api/v1/sales-invoice-history/import', [
            'file' => $this->validFile(),
            'warehouse_id' => $this->warehouse->id,
        ])->assertCreated();

        $batch = $response->json('data');

        $this->assertSame('previewed', $batch['status']);
        $this->assertSame([], $batch['preview_summary']['needs_resolution']);
        $this->assertSame(1, $batch['preview_summary']['valid_count']);
        $this->assertNotEmpty($batch['preview_summary']['warnings'], 'the mandatory no-AR/GL/stock warning must always be present');

        $this->postJson("/api/v1/sales-invoice-history/import/{$batch['id']}/resolve", ['resolutions' => []])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_store_rejects_a_file_that_is_not_sales_invoice_listing_detail(): void
    {
        $csv = $this->csv([
            ['SUPPLIER PURCHASE LISTING'],
            ['DATE', 'DOCUMENT #', 'SUPPLIER NAME', 'AMOUNT (INCLUDE TAX)'],
            ['19/08/2026', 'BRM/041/038', 'PT ABC', 30857736],
        ]);

        $this->postJson('/api/v1/sales-invoice-history/import', [
            'file' => $csv,
            'warehouse_id' => $this->warehouse->id,
        ])->assertStatus(422);
    }

    public function test_store_requires_warehouse_id(): void
    {
        $this->postJson('/api/v1/sales-invoice-history/import', [
            'file' => $this->validFile(),
        ])->assertStatus(422);
    }
}
