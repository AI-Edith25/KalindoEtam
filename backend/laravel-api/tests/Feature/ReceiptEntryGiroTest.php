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

/** D2 (UAT review 2026-08-12) — Payment Method (Giro/Cek) as a real field alongside Cash/Bank Account, with conditional number+due date. */
class ReceiptEntryGiroTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;
    protected ChartOfAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->cashAccount = ChartOfAccount::query()->create(['code' => '1100', 'name' => 'Cash and Bank', 'account_type' => 'asset', 'is_cash_bank' => true]);

        Permission::query()->firstOrCreate(['name' => 'finance.incoming_payment.create', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('finance.incoming_payment.create');
        Sanctum::actingAs($user);
    }

    protected function basePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'total_amount' => 100000,
        ], $overrides);
    }

    public function test_giro_payment_method_without_giro_number_and_due_date_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', $this->basePayload(['payment_method' => 'giro']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['giro_number', 'giro_due_date']);
    }

    public function test_giro_payment_method_with_number_and_due_date_is_accepted_and_persisted(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', $this->basePayload([
            'payment_method' => 'giro',
            'giro_number' => 'GRO-0001',
            'giro_due_date' => now()->addDays(14)->toDateString(),
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.payment_method', 'giro');
        $response->assertJsonPath('data.giro_number', 'GRO-0001');
    }

    public function test_cash_payment_method_does_not_require_giro_fields(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', $this->basePayload(['payment_method' => 'cash']));

        $response->assertCreated();
        $response->assertJsonPath('data.payment_method', 'cash');
        $response->assertJsonPath('data.giro_number', null);
    }

    public function test_payment_method_is_required(): void
    {
        $response = $this->postJson('/api/v1/receipt-entries', $this->basePayload());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('payment_method');
    }
}
