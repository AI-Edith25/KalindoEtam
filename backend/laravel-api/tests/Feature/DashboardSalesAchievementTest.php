<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\SalesPerson;
use App\Models\SalesTarget;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Dashboard "Pencapaian Sales" panel's aggregation — same
 * submitted-Invoice/CreditNote/DebitNote population and ex-tax netting as
 * DashboardService::financialSummary()'s own Revenue (MTD), re-grouped by
 * sales_person_id instead of by account. See DashboardService::
 * salesAchievement() and each repository's revenue*ForPeriod() method.
 */
class DashboardSalesAchievementTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'master.sales_targets.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.sales_targets.view');
        Sanctum::actingAs($user);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
    }

    protected function makeInvoice(?string $salesPersonId, float $subtotal, float $tax, string $date, string $status = 'submitted'): Invoice
    {
        return Invoice::query()->create([
            'invoice_type' => 'goods',
            'status' => $status,
            'customer_id' => $this->customer->id,
            'sales_person_id' => $salesPersonId,
            'invoice_date' => $date,
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'grand_total' => $subtotal + $tax,
        ]);
    }

    public function test_current_month_achievement_nets_credit_and_debit_notes_per_sales_person(): void
    {
        $budi = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi Santoso']);
        $eko = SalesPerson::query()->create(['code' => 'SP2', 'name' => 'EKO']);
        $citra = SalesPerson::query()->create(['code' => 'SP3', 'name' => 'Citra']); // target, no transactions

        // Budi: Invoice 1,000,000 net - Credit Note 100,000 net = 900,000.
        $invoiceBudi = $this->makeInvoice($budi->id, 1_000_000, 110_000, '2026-08-05');
        CreditNote::query()->create([
            'invoice_id' => $invoiceBudi->id,
            'customer_id' => $this->customer->id,
            'credit_note_date' => '2026-08-06',
            'reason' => 'price_adjustment',
            'subtotal' => 100_000,
            'total_amount' => 111_000,
            'tax_amount' => 11_000,
            'status' => 'submitted',
        ]);
        // A reversed Credit Note must NOT reduce Budi's total — its ledger effect was undone.
        CreditNote::query()->create([
            'invoice_id' => $invoiceBudi->id,
            'customer_id' => $this->customer->id,
            'credit_note_date' => '2026-08-07',
            'reason' => 'price_adjustment',
            'subtotal' => 999_999,
            'total_amount' => 999_999,
            'status' => 'submitted',
            'is_reversed' => true,
        ]);

        // EKO: Invoice 500,000 net + Debit Note 70,000 net (goods+other) = 570,000.
        $invoiceEko = $this->makeInvoice($eko->id, 500_000, 55_000, '2026-08-10');
        DebitNote::query()->create([
            'invoice_id' => $invoiceEko->id,
            'customer_id' => $this->customer->id,
            'debit_note_date' => '2026-08-11',
            'reason' => 'price_correction',
            'subtotal_goods' => 50_000,
            'subtotal_other' => 20_000,
            'total_amount' => 77_700,
            'tax_amount' => 7_700,
            'status' => 'submitted',
        ]);

        // Unassigned: no sales person at all — must not count toward anyone.
        $this->makeInvoice(null, 200_000, 22_000, '2026-08-12');

        // Draft invoice — never posted, must be excluded entirely.
        $this->makeInvoice($budi->id, 5_000_000, 0, '2026-08-13', 'draft');

        SalesTarget::query()->create(['sales_person_id' => $budi->id, 'period_month' => 8, 'period_year' => 2026, 'target_amount' => 2_000_000]);
        SalesTarget::query()->create(['sales_person_id' => $citra->id, 'period_month' => 8, 'period_year' => 2026, 'target_amount' => 500_000]);

        $response = $this->getJson('/api/v1/dashboard/sales-achievement?month=8&year=2026');
        $response->assertOk();

        $data = $response->json('data');
        $rows = collect($data['rows'])->keyBy('sales_person_id');

        $budiRow = $rows[$budi->id];
        $this->assertEquals(900000.0, $budiRow['achieved_amount']);
        $this->assertEquals(2000000.0, $budiRow['target_amount']);
        $this->assertEquals(1100000.0, $budiRow['shortfall_amount']);
        $this->assertEquals(45.0, $budiRow['achievement_percent']);

        $ekoRow = $rows[$eko->id];
        $this->assertEquals(570000.0, $ekoRow['achieved_amount']);
        $this->assertNull($ekoRow['target_amount']);
        $this->assertNull($ekoRow['shortfall_amount']);
        $this->assertNull($ekoRow['achievement_percent']);

        $citraRow = $rows[$citra->id];
        $this->assertEquals(0.0, $citraRow['achieved_amount']);
        $this->assertEquals(500000.0, $citraRow['shortfall_amount']);

        $this->assertEquals(200000.0, $data['unassigned']['achieved_amount']);
    }

    public function test_past_month_uses_the_full_month_not_truncated_at_today(): void
    {
        $budi = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi Santoso']);
        // "Today" in this environment is 2026-08-28 — July is unambiguously a past, closed month,
        // so a transaction on its very last day must still be counted in full.
        $this->makeInvoice($budi->id, 300_000, 33_000, '2026-07-31');

        $response = $this->getJson('/api/v1/dashboard/sales-achievement?month=7&year=2026');
        $response->assertOk();

        $rows = collect($response->json('data.rows'))->keyBy('sales_person_id');
        $this->assertEquals(300000.0, $rows[$budi->id]['achieved_amount']);
    }

    public function test_sales_person_with_no_target_and_no_transactions_is_not_listed(): void
    {
        SalesPerson::query()->create(['code' => 'SP9', 'name' => 'Nobody']);

        $response = $this->getJson('/api/v1/dashboard/sales-achievement?month=8&year=2026');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.rows'));
        $this->assertNull($response->json('data.unassigned'));
    }
}
