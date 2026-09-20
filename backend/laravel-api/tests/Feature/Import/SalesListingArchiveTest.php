<?php

namespace Tests\Feature\Import;

use App\Models\CustomerOutstandingSnapshot;
use App\Models\Permission;
use App\Models\SalesListingSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SalesListingArchiveImportService + SalesListingArchiveService against a synthetic "01 Sales
 * Listing" fixture (no real Skybiz export was provided) -- covers the ticket's own verification
 * #2 (Sales Listing and Customer Sales totals must agree, same source rows) and the AR-join
 * rule for Payment Status/Outstanding AR.
 */
class SalesListingArchiveTest extends TestCase
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

    private function fixtureFile(string $originalName = 'xlsSalesListing.xlsx'): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/xlsSalesListing.xlsx'), $originalName, null, null, true);
    }

    private function importFixture(): SalesListingSnapshot
    {
        $upload = $this->post('/api/v1/sales-archive/snapshots', ['file' => $this->fixtureFile(), 'file_type' => 'sales_listing']);
        $upload->assertCreated();
        $batchId = $upload->json('data.id');
        $resolve = $this->postJson("/api/v1/sales-archive/batches/{$batchId}/resolve");
        $resolve->assertCreated();

        return SalesListingSnapshot::query()->firstOrFail();
    }

    public function test_preflight_parses_the_period_and_totals_correctly(): void
    {
        $upload = $this->post('/api/v1/sales-archive/snapshots', ['file' => $this->fixtureFile(), 'file_type' => 'sales_listing']);
        $upload->assertCreated();

        $preview = $upload->json('data.preview_summary');
        $this->assertSame('2026-08-20', $preview['period_start']);
        $this->assertSame('2026-09-20', $preview['period_end']);
        $this->assertSame(3, $preview['total_documents']);
        $this->assertEqualsWithDelta(2900000, $preview['grand_total_amount_excl_tax'], 0.01);
        $this->assertEqualsWithDelta(3144000, $preview['grand_total_amount_incl_tax'], 0.01);
        $this->assertSame([], $preview['failed_rows']);
    }

    /** Ticket verification #5: uploading File A content with the Product Sales Detail slot must be rejected. */
    public function test_uploading_the_wrong_file_type_slot_is_rejected(): void
    {
        $response = $this->post('/api/v1/sales-archive/snapshots', ['file' => $this->fixtureFile(), 'file_type' => 'product_sales_detail']);

        $response->assertStatus(422);
        $this->assertStringContainsString('01 Sales Listing', $response->json('message'));
    }

    /** Ticket verification #2: Sales Listing and Customer Sales totals must agree exactly -- same source rows. */
    public function test_sales_listing_and_customer_sales_totals_agree(): void
    {
        $this->importFixture();

        $listing = $this->getJson('/api/v1/sales-archive/sales-listing')->json();
        $customers = $this->getJson('/api/v1/sales-archive/customer-sales')->json();

        $this->assertEqualsWithDelta($listing['meta']['kpis']['gross'], array_sum(array_column($customers['data'], 'amount_incl_tax')), 0.01);
        $this->assertCount(3, $listing['data']);
        $this->assertCount(2, $customers['data']);
    }

    /** AR-join rule: found in the latest AR snapshot -> Belum Lunas (+unpaid amount); not found -> Lunas; no AR snapshot at all -> "-" (null). */
    public function test_payment_status_joins_against_the_latest_ar_snapshot_by_document_number(): void
    {
        $this->importFixture();

        $noArYet = $this->getJson('/api/v1/sales-archive/sales-listing')->json('data');
        foreach ($noArYet as $row) {
            $this->assertNull($row['payment_status']);
            $this->assertNull($row['outstanding_ar']);
        }

        $ar = CustomerOutstandingSnapshot::query()->create([
            'source_filename' => 'x.xlsx', 'snapshot_as_of_date' => '2026-09-20',
            'total_rows' => 1, 'total_customers' => 1, 'grand_total_unpaid' => 500000, 'grand_total_overdue' => 0,
        ]);
        $ar->lines()->create([
            'customer_code' => 'C-001', 'customer_name' => 'PT Customer A', 'txn_date' => '2026-09-01',
            'ref_no' => 'SI/KE/001/09/2026', 'invoice_amount' => 1110000, 'paid_amount' => 610000,
            'unpaid_amount' => 500000, 'terms_days' => 30, 'due_date' => '2026-10-01', 'overdue_amount' => 0, 'overdue_days' => 0,
        ]);

        $rows = collect($this->getJson('/api/v1/sales-archive/sales-listing')->json('data'))->keyBy('document_number');

        $this->assertSame('unpaid', $rows['SI/KE/001/09/2026']['payment_status']);
        $this->assertEqualsWithDelta(500000, $rows['SI/KE/001/09/2026']['outstanding_ar'], 0.01);
        $this->assertSame('paid', $rows['SI/KE/002/09/2026']['payment_status']);
        $this->assertEqualsWithDelta(0, $rows['SI/KE/002/09/2026']['outstanding_ar'], 0.01);
    }

    /** Ticket verification #6: a second, different period is added alongside the first, not replacing it. */
    public function test_a_different_period_is_added_alongside_the_first(): void
    {
        $this->importFixture();

        $secondPeriodFile = new UploadedFile(base_path('tests/Fixtures/xlsSalesListingPeriod2.xlsx'), 'xlsSalesListingPeriod2.xlsx', null, null, true);
        $upload = $this->post('/api/v1/sales-archive/snapshots', ['file' => $secondPeriodFile, 'file_type' => 'sales_listing']);
        $upload->assertCreated();
        $this->postJson('/api/v1/sales-archive/batches/'.$upload->json('data.id').'/resolve')->assertCreated();

        $this->assertSame(2, SalesListingSnapshot::query()->count());
        $rows = $this->getJson('/api/v1/sales-archive/sales-listing')->json('data');
        $this->assertCount(4, $rows);
    }

    /** Re-importing the SAME period replaces just that snapshot -- row/customer counts don't double. */
    public function test_reimporting_the_same_period_replaces_it_instead_of_duplicating(): void
    {
        $this->importFixture();
        $this->importFixture();

        $this->assertSame(1, SalesListingSnapshot::query()->count());
        $rows = $this->getJson('/api/v1/sales-archive/sales-listing')->json('data');
        $this->assertCount(3, $rows);
    }
}
