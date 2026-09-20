<?php

namespace Tests\Feature\Import;

use App\Models\CustomerOutstandingSnapshot;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exercises CustomerOutstandingArchiveImportService against the real
 * "Customer Unpaid Bills With Overdue Advice" export (573 customers, 6,690
 * sheet rows). The acceptance bar (per the ticket) is exact: Grand Total
 * Unpaid = Rp 135.358.981.745,70 and Overdue = Rp 126.726.071.682,93 must
 * match the file's own printed Grand Total row bit-for-bit -- the import
 * itself already refuses to commit if its own parsed sum disagrees with
 * that row (or any per-customer subtotal), so a successful import here is
 * already a strong signal, and the exact totals are asserted again below.
 */
class CustomerOutstandingArchiveRealFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'reports.ar_archive.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'reports.ar_archive.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['reports.ar_archive.view', 'reports.ar_archive.import']);
        Sanctum::actingAs($user);
    }

    private function fixtureFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/xlsCustomerOutstandingBills.xlsx'),
            'xlsCustomerOutstandingBills.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    public function test_import_matches_the_files_own_grand_total_exactly(): void
    {
        $response = $this->post('/api/v1/customer-outstanding-archive/snapshots', ['file' => $this->fixtureFile()]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame('2026-09-30', $data['snapshot_as_of_date']);
        $this->assertSame(573, $data['total_customers']);
        $this->assertSame('135358981745.70', $data['grand_total_unpaid']);
        $this->assertSame('126726071682.93', $data['grand_total_overdue']);

        $snapshot = CustomerOutstandingSnapshot::query()->first();
        $this->assertNotNull($snapshot);
        $this->assertSame($data['total_rows'], $snapshot->lines()->count());

        // Independently re-sum straight from the DB rows -- not just trusting the header
        // columns the import itself computed, in case they diverged from what was actually
        // written.
        $sumUnpaid = (float) $snapshot->lines()->sum('unpaid_amount');
        $sumOverdue = (float) $snapshot->lines()->sum('overdue_amount');
        $this->assertEqualsWithDelta(135358981745.70, $sumUnpaid, 0.01);
        $this->assertEqualsWithDelta(126726071682.93, $sumOverdue, 0.01);
    }

    public function test_a_second_import_creates_a_new_snapshot_without_touching_the_first(): void
    {
        $this->post('/api/v1/customer-outstanding-archive/snapshots', ['file' => $this->fixtureFile()])->assertCreated();
        $this->post('/api/v1/customer-outstanding-archive/snapshots', ['file' => $this->fixtureFile()])->assertCreated();

        $this->assertSame(2, CustomerOutstandingSnapshot::query()->count());

        $snapshots = $this->getJson('/api/v1/customer-outstanding-archive/snapshots');
        $snapshots->assertOk();
        $this->assertCount(2, $snapshots->json('data'));
    }

    public function test_grouped_detail_groups_by_customer_with_correct_subtotals_and_supports_filtering(): void
    {
        $upload = $this->post('/api/v1/customer-outstanding-archive/snapshots', ['file' => $this->fixtureFile()]);
        $snapshotId = $upload->json('data.id');

        $show = $this->getJson("/api/v1/customer-outstanding-archive/snapshots/{$snapshotId}");
        $show->assertOk();

        $customers = collect($show->json('data.customers'));
        $this->assertCount(573, $customers);
        $this->assertEqualsWithDelta(135358981745.70, $show->json('data.grand_total_unpaid'), 0.01);

        $firstCustomer = $customers->firstWhere('customer_code', 'C-0001');
        $this->assertNotNull($firstCustomer);
        $this->assertCount(1, $firstCustomer['rows']);
        $this->assertEqualsWithDelta(6225000.27, $firstCustomer['subtotal_unpaid'], 0.01);

        // Filter by customer code -- narrows to just that one customer.
        $filtered = $this->getJson("/api/v1/customer-outstanding-archive/snapshots/{$snapshotId}?customer=C-0001");
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data.customers'));
    }
}
