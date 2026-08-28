<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The new "Rentang Nominal" (min_amount/max_amount) filter on Credit Notes
 * and Debit Notes — see CreditNoteRepository/DebitNoteRepository::applyFilters().
 */
class CreditDebitNoteAmountRangeFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;
    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'sales.credit_notes.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'sales.debit_notes.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.credit_notes.view', 'sales.debit_notes.view']);
        Sanctum::actingAs($user);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->invoice = Invoice::query()->create([
            'invoice_type' => 'goods',
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 1000000,
            'grand_total' => 1000000,
        ]);
    }

    protected function makeCreditNote(float $amount): CreditNote
    {
        return CreditNote::query()->create([
            'invoice_id' => $this->invoice->id,
            'customer_id' => $this->customer->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'price_adjustment',
            'subtotal' => $amount,
            'total_amount' => $amount,
        ]);
    }

    protected function makeDebitNote(float $amount): DebitNote
    {
        return DebitNote::query()->create([
            'invoice_id' => $this->invoice->id,
            'customer_id' => $this->customer->id,
            'debit_note_date' => now()->toDateString(),
            'reason' => 'price_correction',
            'subtotal_goods' => $amount,
            'subtotal_other' => 0,
            'total_amount' => $amount,
        ]);
    }

    public function test_credit_note_amount_range_narrows_results(): void
    {
        $inRange = $this->makeCreditNote(50000);
        $this->makeCreditNote(10000);
        $this->makeCreditNote(200000);

        $response = $this->getJson('/api/v1/credit-notes?min_amount=30000&max_amount=100000');

        $response->assertOk();
        $this->assertEquals([$inRange->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_debit_note_amount_range_narrows_results(): void
    {
        $inRange = $this->makeDebitNote(50000);
        $this->makeDebitNote(10000);
        $this->makeDebitNote(200000);

        $response = $this->getJson('/api/v1/debit-notes?min_amount=30000&max_amount=100000');

        $response->assertOk();
        $this->assertEquals([$inRange->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_max_amount_below_min_amount_returns_a_validation_error(): void
    {
        $response = $this->getJson('/api/v1/credit-notes?min_amount=100000&max_amount=1000');

        $response->assertStatus(422);
    }
}
