<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Official Receipt's new Payment Type choice: 'customer' (the pre-existing flow — settles a
 * customer's AR, posts Dr Cash/Cr 1150 Unapplied Customer Payments) vs 'other_income' (money
 * received that isn't a customer AR settlement — no customer, posts Dr Cash/Cr the chosen income
 * account directly, never allocated). Mirrors PaymentEntry's own Supplier/General Expense split.
 */
class ReceiptEntryOtherIncomeTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected ChartOfAccount $cashAccount;

    protected ChartOfAccount $incomeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->cashAccount = ChartOfAccount::query()->create(['code' => '1100', 'name' => 'Cash and Bank', 'account_type' => 'asset', 'is_cash_bank' => true]);
        $this->incomeAccount = ChartOfAccount::query()->create(['code' => '4900', 'name' => 'Other Income', 'account_type' => 'revenue']);
        // The 'customer' flow's own suspense account — see ReceiptEntry::journalLines()'s hardcoded '1150'.
        ChartOfAccount::query()->create(['code' => '1150', 'name' => 'Uang Muka Pelanggan', 'account_type' => 'liability']);

        Permission::query()->firstOrCreate(['name' => 'finance.incoming_payment.create', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'finance.incoming_payment.update', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['finance.incoming_payment.create', 'finance.incoming_payment.update']);
        Sanctum::actingAs($user);
    }

    public function test_customer_type_still_requires_customer_id(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', [
            'payment_type' => 'customer',
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'total_amount' => 100000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['customer_id']);
    }

    public function test_other_income_requires_income_account_and_description_not_customer(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', [
            'payment_type' => 'other_income',
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'total_amount' => 50000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['income_account_id', 'description']);
    }

    public function test_other_income_creates_and_submits_with_no_customer(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', [
            'payment_type' => 'other_income',
            'income_account_id' => $this->incomeAccount->id,
            'description' => 'Penjualan scrap besi',
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'payment_method' => 'cash',
            'total_amount' => 75000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payment_type', 'other_income');
        $response->assertJsonPath('data.customer_id', null);
        $response->assertJsonPath('data.income_account_id', $this->incomeAccount->id);
        $id = $response->json('data.id');

        $submit = $this->postJson("/api/v1/receipt-entries/{$id}/submit");
        $submit->assertOk();
        $submit->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('journal_entry_lines', [
            'chart_of_account_id' => $this->incomeAccount->id, 'credit' => 75000, 'debit' => 0,
        ]);
    }

    public function test_customer_type_unaffected_posts_to_unapplied_customer_payments(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', [
            'payment_type' => 'customer',
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'payment_method' => 'cash',
            'total_amount' => 100000,
        ]);

        $response->assertCreated();
        $id = $response->json('data.id');

        $submit = $this->postJson("/api/v1/receipt-entries/{$id}/submit");
        $submit->assertOk();

        $suspense = ChartOfAccount::query()->where('code', '1150')->firstOrFail();
        $this->assertDatabaseHas('journal_entry_lines', [
            'chart_of_account_id' => $suspense->id, 'credit' => 100000, 'debit' => 0,
        ]);
    }
}
