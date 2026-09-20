<?php

namespace Tests\Feature\Import;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Synthetic fixture (tests/Fixtures/xlsCustomerUnpaidBillsEdgeCases.xlsx) purpose-built to
 * exercise the parsing rules the ticket calls out explicitly:
 * - Header row NOT at the usual row 5 (an extra blank row pushes it to row 6) -- must be found
 *   by content ("Ref. No"), never a hardcoded row number.
 * - Indonesian-format numbers stored as TEXT ("6.225.000,27": dot thousands, comma decimal) on
 *   customer C-0001's row -- the single most commonly miscounted case per the ticket.
 * - A row with an unparseable date (customer C-0002's second row) -- must be skipped and
 *   reported, never abort the whole import.
 * - A missing subtotal row for C-0002 (jumps straight to "Customer : C-0003") -- must be a soft
 *   warning, not fatal.
 */
class CustomerOutstandingArchiveEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'reports.ar_archive.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.ar_archive.import');
        Sanctum::actingAs($user);
    }

    private function fixtureFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/xlsCustomerUnpaidBillsEdgeCases.xlsx'),
            'xlsCustomerUnpaidBillsEdgeCases.xlsx',
            null,
            null,
            true,
        );
    }

    public function test_header_found_by_content_indonesian_text_numbers_parsed_correctly_bad_row_and_missing_subtotal_reported(): void
    {
        $upload = $this->post('/api/v1/customer-outstanding-archive/snapshots', ['file' => $this->fixtureFile()]);
        $upload->assertCreated();

        $preview = $upload->json('data.preview_summary');

        // 3 customers, 3 valid rows (the bad-date row excluded) -- header detection and the
        // Indonesian-text-number parsing on C-0001's row both worked, or these numbers would be
        // wildly off (e.g. 6.22 instead of 6,225,000.27) and this assertion would fail loudly.
        $this->assertSame(3, $preview['total_customers']);
        $this->assertSame(3, $preview['total_rows']);
        $this->assertEqualsWithDelta(6225000.27 + 925000.19 + 1000000, $preview['total_unpaid'], 0.01);

        // The bad-date row is reported, not silently dropped or fatal.
        $this->assertCount(1, $preview['failed_rows']);
        $this->assertStringContainsString('Tanggal tidak valid', $preview['failed_rows'][0]['reason']);

        // The missing subtotal for C-0002 is a soft warning (file_unpaid/file_overdue null,
        // since there was no subtotal row to compare against at all).
        $this->assertCount(1, $preview['subtotal_mismatches']);
        $this->assertSame('C-0002', $preview['subtotal_mismatches'][0]['customer_code']);
        $this->assertNull($preview['subtotal_mismatches'][0]['file_unpaid']);

        // Grand Total (8,150,000.46) matches what was actually parsed -- no mismatch reported.
        $this->assertNull($preview['grand_total_mismatch']);
    }
}
