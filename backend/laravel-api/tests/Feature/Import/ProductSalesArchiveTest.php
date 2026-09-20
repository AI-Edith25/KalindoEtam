<?php

namespace Tests\Feature\Import;

use App\Models\Permission;
use App\Models\ProductSalesSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ProductSalesArchiveImportService + ProductSalesArchiveService against a synthetic "13 Product
 * Sales Report - Detail" fixture with one deliberately-wrong item subtotal (ITEM-002: file says
 * 250000, detail rows actually sum to 260000) -- proves the ticket's own verification #1: a
 * wrong item subtotal must surface as a mismatch, not silently corrupt the computed total.
 */
class ProductSalesArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'reports.sales_archive.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'reports.sales_archive.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['reports.sales_archive.view', 'reports.sales_archive.import']);
        Sanctum::actingAs($user);
    }

    private function fixtureFile(string $originalName = 'xlsProductSalesDetail.xlsx'): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/xlsProductSalesDetail.xlsx'), $originalName, null, null, true);
    }

    /** Ticket verification #1: ITEM-001's subtotal matches (500000, computed 300000+200000); ITEM-002 is deliberately wrong (file 250000, computed 260000) and must be flagged, not silently summed wrong. */
    public function test_preflight_flags_the_deliberately_wrong_item_subtotal(): void
    {
        $upload = $this->post('/api/v1/sales-archive/snapshots', ['file' => $this->fixtureFile(), 'file_type' => 'product_sales_detail']);
        $upload->assertCreated();

        $preview = $upload->json('data.preview_summary');
        $this->assertSame('2026-08-20', $preview['period_start']);
        $this->assertSame('2026-09-20', $preview['period_end']);
        $this->assertSame(2, $preview['total_items']);
        $this->assertEqualsWithDelta(760000, $preview['grand_total_amount_excl_tax'], 0.01);
        $this->assertSame([], $preview['failed_rows']);

        $this->assertCount(1, $preview['subtotal_mismatches']);
        $mismatch = $preview['subtotal_mismatches'][0];
        $this->assertSame('ITEM-002', $mismatch['item_code']);
        $this->assertEqualsWithDelta(250000, $mismatch['file_amount'], 0.01);
        $this->assertEqualsWithDelta(260000, $mismatch['computed_amount'], 0.01);

        $this->assertNotNull($preview['grand_total_mismatch']);
        $this->assertEqualsWithDelta(750000, $preview['grand_total_mismatch']['file_amount'], 0.01);
        $this->assertEqualsWithDelta(760000, $preview['grand_total_mismatch']['computed_amount'], 0.01);
    }

    public function test_commit_stores_raw_detail_lines_and_aggregates_per_item(): void
    {
        $upload = $this->post('/api/v1/sales-archive/snapshots', ['file' => $this->fixtureFile(), 'file_type' => 'product_sales_detail']);
        $this->postJson('/api/v1/sales-archive/batches/'.$upload->json('data.id').'/resolve')->assertCreated();

        $snapshot = ProductSalesSnapshot::query()->firstOrFail();
        $this->assertSame(3, $snapshot->lines()->count());

        $rows = collect($this->getJson('/api/v1/sales-archive/product-sales?group=item')->json('data'))->keyBy('item_code');
        $this->assertEqualsWithDelta(500000, $rows['ITEM-001']['amount'], 0.01);
        $this->assertEqualsWithDelta(260000, $rows['ITEM-002']['amount'], 0.01);

        $customers = $this->getJson('/api/v1/sales-archive/product-sales/ITEM-001/customers')->json('data');
        $this->assertCount(2, $customers);
    }

    /** Ticket verification #5: uploading File B content with the Sales Listing slot must be rejected. */
    public function test_uploading_the_wrong_file_type_slot_is_rejected(): void
    {
        $response = $this->post('/api/v1/sales-archive/snapshots', ['file' => $this->fixtureFile(), 'file_type' => 'sales_listing']);

        $response->assertStatus(422);
        $this->assertStringContainsString('13 Product Sales Report', $response->json('message'));
    }

    /** Ticket verification #3: importing File A only, Product Sales must stay a clean empty state, not error. */
    public function test_product_sales_is_empty_without_error_when_only_file_a_is_imported(): void
    {
        $this->assertFalse((new \App\Services\ProductSalesArchiveService)->hasSnapshot());

        $response = $this->getJson('/api/v1/sales-archive/product-sales');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    /** Ticket verification #4: importing File B only, Sales Listing/Customer Sales must stay a clean empty state. */
    public function test_sales_listing_is_empty_without_error_when_only_file_b_is_imported(): void
    {
        $upload = $this->post('/api/v1/sales-archive/snapshots', ['file' => $this->fixtureFile(), 'file_type' => 'product_sales_detail']);
        $this->postJson('/api/v1/sales-archive/batches/'.$upload->json('data.id').'/resolve')->assertCreated();

        $meta = $this->getJson('/api/v1/sales-archive/meta')->json('data');
        $this->assertTrue($meta['product_sales_detail']['has_snapshot']);
        $this->assertFalse($meta['sales_listing']['has_snapshot']);

        $listing = $this->getJson('/api/v1/sales-archive/sales-listing');
        $listing->assertOk();
        $this->assertSame([], $listing->json('data'));
    }
}
