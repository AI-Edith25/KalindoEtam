<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Official Receipt's 'customer' Payment Type now branches on the selected customer's own
 * receivable_category: a 'C' customer keeps going through 1150 Unapplied Customer Payments
 * (unchanged — regression-guarded here), a PK/PL customer posts straight to its own Piutang
 * account (112.02/112.03) with no suspense leg, since it has no Invoice to later allocate
 * against. See ReceiptEntry::journalLines() and docs/superpowers/specs/
 * 2026-10-10-customer-receivable-categories-design.md.
 */
class ReceiptEntryReceivableCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $employeeCustomer;

    protected Customer $tradeCustomer;

    protected ChartOfAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->employeeCustomer = Customer::query()->create(['customer_code' => 'PK-0001', 'customer_name' => 'Budi', 'receivable_category' => 'PK']);
        $this->tradeCustomer = Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Toko Jaya', 'receivable_category' => 'C']);
        $this->cashAccount = ChartOfAccount::query()->where('code', '1100')->first()
            ?? ChartOfAccount::query()->create(['code' => '1100', 'name' => 'Kas', 'account_type' => 'asset', 'is_cash_bank' => true]);

        Permission::query()->firstOrCreate(['name' => 'finance.incoming_payment.create', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'finance.incoming_payment.update', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['finance.incoming_payment.create', 'finance.incoming_payment.update']);
        Sanctum::actingAs($user);
    }

    public function test_pk_customer_posts_straight_to_piutang_karyawan_with_no_suspense_leg(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', [
            'payment_type' => 'customer',
            'customer_id' => $this->employeeCustomer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'payment_method' => 'cash',
            'total_amount' => 200000,
        ])->assertCreated();

        $id = $response->json('data.id');
        $this->postJson("/api/v1/receipt-entries/{$id}/submit")->assertOk();

        $piutangKaryawan = ChartOfAccount::query()->where('code', '112.02')->firstOrFail();
        $this->assertDatabaseHas('journal_entry_lines', [
            'chart_of_account_id' => $piutangKaryawan->id, 'credit' => 200000, 'debit' => 0,
        ]);

        $suspense = ChartOfAccount::query()->where('code', '1150')->first();
        if ($suspense) {
            $this->assertDatabaseMissing('journal_entry_lines', [
                'chart_of_account_id' => $suspense->id, 'credit' => 200000,
            ]);
        }
    }

    public function test_trade_customer_is_unaffected_and_still_posts_to_unapplied_customer_payments(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', [
            'payment_type' => 'customer',
            'customer_id' => $this->tradeCustomer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'payment_method' => 'cash',
            'total_amount' => 150000,
        ])->assertCreated();

        $id = $response->json('data.id');
        $this->postJson("/api/v1/receipt-entries/{$id}/submit")->assertOk();

        $suspense = ChartOfAccount::query()->where('code', '1150')->firstOrFail();
        $this->assertDatabaseHas('journal_entry_lines', [
            'chart_of_account_id' => $suspense->id, 'credit' => 150000, 'debit' => 0,
        ]);
    }
}
