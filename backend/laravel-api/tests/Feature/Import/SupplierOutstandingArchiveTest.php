<?php

namespace Tests\Feature\Import;

use App\Models\Permission;
use App\Models\SupplierOutstandingSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SupplierOutstandingArchiveImportService against a synthetic fixture (no real Skybiz export
 * was provided this time) covering the ticket's explicit file-validation rules: accept by
 * filename OR content, reject a customer file with a page-redirect message, reject an
 * unrecognized format, and accept content-matching files under a completely unrelated filename.
 */
class SupplierOutstandingArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'reports.ap_archive.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'reports.ap_archive.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['reports.ap_archive.view', 'reports.ap_archive.import']);
        Sanctum::actingAs($user);
    }

    private function fixtureFile(string $originalName = 'xlsSupplierOutstandingBills.xlsx'): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/xlsSupplierOutstandingBills.xlsx'), $originalName, null, null, true);
    }

    public function test_preflight_parses_correctly_with_the_expected_filename(): void
    {
        $upload = $this->post('/api/v1/supplier-outstanding-archive/snapshots', ['file' => $this->fixtureFile()]);
        $upload->assertCreated();

        $preview = $upload->json('data.preview_summary');
        $this->assertSame('2026-09-30', $preview['snapshot_as_of_date']);
        $this->assertSame(2, $preview['total_suppliers']);
        $this->assertSame(3, $preview['total_rows']);
        $this->assertEqualsWithDelta(8500000, $preview['total_unpaid'], 0.01);
        $this->assertEqualsWithDelta(7000000, $preview['total_overdue'], 0.01);
        $this->assertSame([], $preview['failed_rows']);
        $this->assertSame([], $preview['subtotal_mismatches']);
        $this->assertNull($preview['grand_total_mismatch']);
    }

    /** The ticket's own explicit verification: a random filename must not matter, only the content. */
    public function test_file_is_accepted_by_content_even_with_a_completely_unrelated_filename(): void
    {
        $upload = $this->post('/api/v1/supplier-outstanding-archive/snapshots', ['file' => $this->fixtureFile('data.xlsx')]);

        $upload->assertCreated();
        $this->assertSame(2, $upload->json('data.preview_summary.total_suppliers'));
    }

    public function test_customer_file_is_rejected_with_a_message_pointing_to_the_right_page(): void
    {
        $customerFile = new UploadedFile(base_path('tests/Fixtures/xlsCustomerOutstandingBills.xlsx'), 'xlsCustomerOutstandingBills.xlsx', null, null, true);

        $response = $this->post('/api/v1/supplier-outstanding-archive/snapshots', ['file' => $customerFile]);

        $response->assertStatus(422);
        $this->assertStringContainsString('AR Detail', $response->json('message'));
        $this->assertSame(0, SupplierOutstandingSnapshot::query()->count());
    }

    /** Even a customer file under a misleading filename is still caught, since the check also reads the file's own first row. */
    public function test_customer_file_renamed_to_look_like_a_supplier_file_is_still_rejected(): void
    {
        $customerFile = new UploadedFile(base_path('tests/Fixtures/xlsCustomerOutstandingBills.xlsx'), 'xlsSupplierOutstandingBills.xlsx', null, null, true);

        $response = $this->post('/api/v1/supplier-outstanding-archive/snapshots', ['file' => $customerFile]);

        $response->assertStatus(422);
        $this->assertStringContainsString('AR Detail', $response->json('message'));
    }

    public function test_unrecognized_file_is_rejected_with_a_format_message(): void
    {
        $wrongFile = new UploadedFile(base_path('tests/Fixtures/xlsStockBalance.xlsx'), 'random.xlsx', null, null, true);

        $response = $this->post('/api/v1/supplier-outstanding-archive/snapshots', ['file' => $wrongFile]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Supplier Outstanding Bills', $response->json('message'));
    }

    public function test_resolve_commits_and_second_import_becomes_the_active_snapshot(): void
    {
        $upload = $this->post('/api/v1/supplier-outstanding-archive/snapshots', ['file' => $this->fixtureFile()]);
        $batchId = $upload->json('data.id');
        $resolve = $this->postJson("/api/v1/supplier-outstanding-archive/batches/{$batchId}/resolve");
        $resolve->assertCreated();

        $snapshot = SupplierOutstandingSnapshot::query()->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(3, $snapshot->lines()->count());
        $this->assertEqualsWithDelta(8500000, (float) $snapshot->lines()->sum('unpaid_amount'), 0.01);

        $show = $this->getJson("/api/v1/supplier-outstanding-archive/snapshots/{$snapshot->id}");
        $show->assertOk();
        $suppliers = collect($show->json('data.suppliers'));
        $this->assertCount(2, $suppliers);
        $s2 = $suppliers->firstWhere('supplier_code', 'S-0002');
        $this->assertSame('PT CONCH - CABANG SMD', $s2['supplier_name']);
        $this->assertCount(2, $s2['rows']);
    }
}
