<?php

namespace Tests\Feature\Import;

use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\TermsOfPayment;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The 1-step import flow: POST /import/{module}/auto — upload, auto-map, preview,
 * commit, all in one request, no manual mapping/fk-resolution/preview screens.
 */
class AutoImportTest extends TestCase
{
    use RefreshDatabase;

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function actingAsWithPermission(string $permission): User
    {
        Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_exact_header_match_auto_commits_with_no_manual_steps(): void
    {
        $this->actingAsWithPermission('master.terms_of_payments.import');

        $csv = "Term Code,Term Description,Day\nCOD,Cash On Delivery,0\nN30,Net 30 Days,30\n";

        $response = $this->post('/api/v1/import/terms-of-payments/auto', ['file' => $this->csvFile('terms.csv', $csv)]);
        $response->assertCreated();

        $this->assertSame(2, TermsOfPayment::query()->count());
        // Queue runs synchronously in tests (see UomImportTest) so this already reads
        // 'completed' by the time the response comes back; production (a real queue
        // worker) would still be 'queued' at this point — the row count is what matters.
        $this->assertContains($response->json('data.status'), ['queued', 'processing', 'completed']);
    }

    public function test_unrecognized_required_header_is_rejected_with_no_partial_effect(): void
    {
        $this->actingAsWithPermission('master.terms_of_payments.import');

        // "Description" header doesn't match "Term Description"/"name" confidently enough
        // (below the exact/synonym bar) — must reject rather than guess.
        $csv = "Kode,Keterangan,Hari\nCOD,Cash On Delivery,0\n";

        $response = $this->post('/api/v1/import/terms-of-payments/auto', ['file' => $this->csvFile('terms.csv', $csv)]);
        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('data.missing_fields'));

        $this->assertSame(0, TermsOfPayment::query()->count());
    }

    public function test_warehouse_import_defaults_every_row_to_transit_type(): void
    {
        $this->actingAsWithPermission('master.warehouses.import');

        $csv = "Code,Description\nBPP,Balikpapan\nSMD,Samarinda\n";

        $response = $this->post('/api/v1/import/warehouses/auto', ['file' => $this->csvFile('branches.csv', $csv)]);
        $response->assertCreated();

        $this->assertSame(2, Warehouse::query()->count());
        $this->assertTrue(Warehouse::query()->get()->every(fn (Warehouse $w) => $w->warehouse_type === WarehouseType::TRANSIT));
    }

    public function test_supplier_import_auto_concatenates_address_from_synthetic_mapping(): void
    {
        $this->actingAsWithPermission('master.suppliers.import');

        // 2 rows with differing values in every column — a single-row file would trip
        // DataCleaner's "constant column" heuristic (1 row = every column trivially
        // "constant") and drop every column from the suggested mapping, which is a
        // real-world non-issue (legacy files run to hundreds of rows) but a genuine
        // footgun for a minimal test fixture.
        $csv = "CusCode,CusName,Tel,Email,Address1,Address2,Address3,Address4\n"
            ."SUP-001,PT Sample Supplier,0541-111,supplier@example.com,Jl. Sample,,Blok B,\n"
            ."SUP-002,PT Second Supplier,0541-222,second@example.com,Jl. Lain,,,\n";

        $response = $this->post('/api/v1/import/suppliers/auto', ['file' => $this->csvFile('suppliers.csv', $csv)]);
        $response->assertCreated();

        $supplier = \App\Models\Supplier::query()->where('supplier_code', 'SUP-001')->first();
        $this->assertNotNull($supplier);
        $this->assertSame('Jl. Sample, Blok B', $supplier->address);
    }

    public function test_items_module_never_populates_standard_rate_even_if_a_price_column_matches(): void
    {
        $this->actingAsWithPermission('master.items.import');
        ItemGroup::query()->create(['name' => 'General']);
        UnitOfMeasurement::query()->create(['name' => 'Pcs']);

        // 2 rows, differing values in EVERY column (including ItemGroup/UOM — a repeated
        // "General"/"Pcs" on both rows would itself trip the constant-column drop and
        // wipe those required fields' mapping too) — see the Supplier test's comment above.
        $csv = "ItemCode,Description,ItemGroup,UOM,Unit Price\nITM-001,Semen,General,Pcs,65000\nITM-002,Besi,Grosir,Kg,120000\n";

        $response = $this->post('/api/v1/import/items/auto', ['file' => $this->csvFile('items.csv', $csv)]);
        $response->assertCreated();

        $item = Item::query()->where('item_code', 'ITM-001')->first();
        $this->assertNotNull($item);
        $this->assertSame('0.00', $item->standard_rate);
    }

    public function test_item_standard_rate_module_updates_existing_item_and_skips_unknown_code(): void
    {
        $this->actingAsWithPermission('master.item_standard_rates.import');
        $group = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        Item::query()->create(['item_code' => 'ITM-001', 'item_name' => 'Semen', 'item_group_id' => $group->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);

        $csv = "ItemCode,UnitPrice,UnitCost\nITM-001,65000,60000\nUNKNOWN,10000,0\n";

        $response = $this->post('/api/v1/import/item-standard-rates/auto', ['file' => $this->csvFile('rates.csv', $csv)]);
        $response->assertCreated();

        $this->assertSame('65000.00', Item::query()->where('item_code', 'ITM-001')->first()->standard_rate);
        $this->assertSame(1, Item::query()->count());
    }

    public function test_forbidden_without_import_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $csv = "Term Code,Term Description,Day\nCOD,Cash On Delivery,0\n";

        $this->post('/api/v1/import/terms-of-payments/auto', ['file' => $this->csvFile('terms.csv', $csv)])
            ->assertForbidden();
    }
}
