<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\ReceiptEntry;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dashboard's "Bank Balancing" widget -- a thin read over
 * BankReconciliationService::getDailyBalancingSummary(), same data the
 * detail page's daily table and a future WhatsApp automation would read.
 */
class DashboardBankBalancingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Permission::query()->firstOrCreate(['name' => 'finance.bank_reconciliation.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('finance.bank_reconciliation.view');
        Sanctum::actingAs($user);
    }

    public function test_shows_not_uploaded_when_no_statement_exists_for_today(): void
    {
        $bankAccount = ChartOfAccount::query()->create([
            'code' => '1101', 'name' => 'BANK BCA SMD 1312', 'account_type' => 'asset',
            'is_active' => true, 'is_cash_bank' => true, 'cash_bank_category' => 'cash_book',
        ]);
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        ReceiptEntry::query()->create([
            'customer_id' => $customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $bankAccount->id,
            'total_amount' => 500000,
            'allocated_amount' => 0,
        ])->submit();

        app(\App\Services\BankStatement\BankReconciliationService::class)
            ->recomputeSummary($bankAccount->id, now()->toDateString(), now()->toDateString());

        $response = $this->getJson('/api/v1/dashboard/bank-balancing');

        $response->assertOk();
        $response->assertJsonPath('data.0.status', 'not_uploaded');
        $response->assertJsonPath('data.0.bank_account_name', 'BANK BCA SMD 1312');
    }
}
