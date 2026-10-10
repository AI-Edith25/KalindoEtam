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
 * Payment Voucher's new Payment Type: 'customer_advance' — paying a PK/PL customer (e.g. an
 * employee cash advance) debits that customer's own Piutang account (112.02/112.03) directly,
 * never the 1250 Advance to Suppliers suspense the Supplier flow uses. See ReceivableCategory
 * and docs/superpowers/specs/2026-10-10-customer-receivable-categories-design.md.
 */
class PaymentEntryCustomerAdvanceTest extends TestCase
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

        Permission::query()->firstOrCreate(['name' => 'finance.outgoing_payment.create', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'finance.outgoing_payment.update', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['finance.outgoing_payment.create', 'finance.outgoing_payment.update']);
        Sanctum::actingAs($user);
    }

    public function test_customer_advance_requires_customer_id(): void
    {
        $response = $this->postJson('/api/v1/payment-entries', [
            'payment_type' => 'customer_advance',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccount->id,
            'amount' => 100000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customer_id']);
    }

    public function test_customer_advance_rejects_a_trade_category_customer(): void
    {
        $response = $this->postJson('/api/v1/payment-entries', [
            'payment_type' => 'customer_advance',
            'customer_id' => $this->tradeCustomer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccount->id,
            'amount' => 100000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customer_id']);
    }

    public function test_customer_advance_posts_straight_to_the_customers_piutang_account(): void
    {
        $response = $this->postJson('/api/v1/payment-entries', [
            'payment_type' => 'customer_advance',
            'customer_id' => $this->employeeCustomer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'cash_account_id' => $this->cashAccount->id,
            'amount' => 500000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.customer_id', $this->employeeCustomer->id);
        $id = $response->json('data.id');

        $submit = $this->postJson("/api/v1/payment-entries/{$id}/submit");
        $submit->assertOk();

        $piutangKaryawan = ChartOfAccount::query()->where('code', '112.02')->firstOrFail();
        $this->assertDatabaseHas('journal_entry_lines', [
            'chart_of_account_id' => $piutangKaryawan->id, 'debit' => 500000, 'credit' => 0,
        ]);

        // Never the Supplier flow's own suspense account.
        $advanceToSuppliers = ChartOfAccount::query()->where('code', '1250')->first();
        if ($advanceToSuppliers) {
            $this->assertDatabaseMissing('journal_entry_lines', [
                'chart_of_account_id' => $advanceToSuppliers->id, 'debit' => 500000,
            ]);
        }
    }
}
