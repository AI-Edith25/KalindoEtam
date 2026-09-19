<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exercises PurchaseHistoryImportController's HTTP contract directly — in particular the ticket
 * requirement that the pre-import summary always shows before anything is queued, even for a
 * clean file with nothing to resolve (store() no longer auto-dispatches; resolve() is the
 * universal confirm-and-queue step).
 */
class PurchaseHistoryImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected Item $placeholderItem;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'reports.purchase.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.purchase.import');
        Sanctum::actingAs($user);

        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->placeholderItem = Item::query()->create([
            'item_code' => 'PLACEHOLDER', 'item_name' => 'Placeholder Line Item', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 0,
        ]);
    }

    private function csv(array $rows): UploadedFile
    {
        $content = implode("\r\n", array_map(
            fn (array $row) => implode(',', array_map(fn ($v) => $v ?? '', $row)),
            $rows
        ))."\r\n";

        return UploadedFile::fake()->createWithContent('test.csv', $content);
    }

    public function test_store_always_leaves_the_batch_previewed_with_a_summary_even_when_nothing_needs_resolving(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'PT ABC', 'is_active' => true]);

        $csv = $this->csv([
            ['SUPPLIER PURCHASE LISTING'],
            ['', '', '', '19/08/2026 - 19/09/2026'],
            ['PT KALINDO ETAM'],
            ['', '', '', '', '', '', '', '', ''],
            ['DATE', 'DOCUMENT #', 'REFERENCE #', 'REFERENCE 2 #', 'SUPPLIER CODE', 'SUPPLIER NAME', 'TYPE', 'AMOUNT (EXCLUDE TAX)', 'TAX', 'AMOUNT (INCLUDE TAX)'],
            ['19/08/2026', 'BRM/041/038', '', '', 'S-0027', 'PT ABC', 'SupInv', 30857736, 0, 30857736],
        ]);

        $response = $this->postJson('/api/v1/purchase-history/import', [
            'file' => $csv,
            'expected_type' => 'supplier_purchase_listing',
            'placeholder_item_id' => $this->placeholderItem->id,
        ])->assertCreated();

        $batch = $response->json('data');

        // Never 'queued' straight away, regardless of needs_resolution being empty — the summary
        // must be seen and explicitly confirmed first.
        $this->assertSame('previewed', $batch['status']);
        $this->assertSame([], $batch['preview_summary']['needs_resolution']);
        $this->assertSame('Supplier Purchase Listing', $batch['preview_summary']['type_label']);
        $this->assertSame(1, $batch['preview_summary']['valid_count']);
        $this->assertNotEmpty($batch['preview_summary']['warnings'], 'the mandatory By Supplier/AP-GL warnings must always be present');

        // Confirming with an explicitly empty resolutions array (nothing needed resolving) must
        // not 422 — 'present', not 'required', on that field.
        $this->postJson("/api/v1/purchase-history/import/{$batch['id']}/resolve", ['resolutions' => []])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued');
    }

    /**
     * Each Purchase Report tab now has its own Import button locked to one file type — uploading a
     * Supplier Purchase Listing file (File A) through the PO Tracking tab's button
     * (expected_type=purchase_order_tracking) must be rejected, not silently processed.
     */
    public function test_store_rejects_a_file_whose_detected_type_does_not_match_the_buttons_expected_type(): void
    {
        $csv = $this->csv([
            ['SUPPLIER PURCHASE LISTING'],
            ['', '', '', '19/08/2026 - 19/09/2026'],
            ['PT KALINDO ETAM'],
            ['', '', '', '', '', '', '', '', ''],
            ['DATE', 'DOCUMENT #', 'REFERENCE #', 'REFERENCE 2 #', 'SUPPLIER CODE', 'SUPPLIER NAME', 'TYPE', 'AMOUNT (EXCLUDE TAX)', 'TAX', 'AMOUNT (INCLUDE TAX)'],
            ['19/08/2026', 'BRM/041/038', '', '', 'S-0027', 'PT ABC', 'SupInv', 30857736, 0, 30857736],
        ]);

        $this->postJson('/api/v1/purchase-history/import', [
            'file' => $csv,
            'expected_type' => 'purchase_order_tracking',
            'warehouse_id' => $this->warehouse->id,
            'placeholder_item_id' => $this->placeholderItem->id,
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'File yang diupload terdeteksi sebagai "Supplier Purchase Listing", bukan "Purchase Order Tracking" — periksa kembali file yang dipilih.']);
    }

    public function test_store_does_not_require_warehouse_or_placeholder_item_for_product_purchase_report(): void
    {
        $csv = $this->csv([
            ['PRODUCT PURCHASE REPORT - SUMMARY'],
            ['Date From', 'Date To', 'Include Tax Y/N'],
            ['01/01/2026', '31/01/2026', 'Yes'],
            ['PT KALINDO ETAM', '', '', '', '', '', '', '', ''],
            ['ITEM #', 'DESCRIPTION', 'INV', 'DN', 'TOTAL', 'CN', 'NET AMT', 'QTY. PUR.', 'CN. QTY.', 'NET QTY.'],
            ['BDR20', 'BENDRAT 20KG', 0, 0, 100000, 0, 100000, 10, 0, 10],
        ]);

        $this->postJson('/api/v1/purchase-history/import', [
            'file' => $csv,
            'expected_type' => 'product_purchase_report',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'previewed');
    }
}
