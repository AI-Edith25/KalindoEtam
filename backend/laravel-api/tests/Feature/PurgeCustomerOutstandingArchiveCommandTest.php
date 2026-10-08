<?php

namespace Tests\Feature;

use App\Models\CustomerOutstandingSnapshot;
use App\Models\CustomerOutstandingSnapshotLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeCustomerOutstandingArchiveCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeSnapshotWithLines(): CustomerOutstandingSnapshot
    {
        $snapshot = CustomerOutstandingSnapshot::query()->create([
            'source_filename' => 'test.xlsx', 'snapshot_as_of_date' => '2026-09-30',
            'total_rows' => 1, 'total_customers' => 1, 'grand_total_unpaid' => 50000, 'grand_total_overdue' => 0,
        ]);
        CustomerOutstandingSnapshotLine::query()->create([
            'snapshot_id' => $snapshot->id, 'customer_code' => 'CUST1', 'customer_name' => 'Test Customer',
            'txn_date' => '2026-09-30', 'ref_no' => 'SI/KE/00001/09/2026', 'invoice_amount' => 50000,
            'paid_amount' => 0, 'unpaid_amount' => 50000, 'due_date' => '2026-10-30', 'overdue_amount' => 0, 'overdue_days' => 0,
        ]);

        return $snapshot;
    }

    public function test_commit_deletes_snapshots_and_cascades_their_lines(): void
    {
        $this->makeSnapshotWithLines();

        $this->artisan('customer-outstanding-archive:purge', ['--commit' => true])->assertExitCode(0);

        $this->assertSame(0, CustomerOutstandingSnapshot::query()->count());
        $this->assertSame(0, CustomerOutstandingSnapshotLine::query()->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->makeSnapshotWithLines();

        $this->artisan('customer-outstanding-archive:purge')->assertExitCode(0);

        $this->assertSame(1, CustomerOutstandingSnapshot::query()->count());
        $this->assertSame(1, CustomerOutstandingSnapshotLine::query()->count());
    }
}
