<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NamingSeries;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Customer Code (customer_code) defaults to a server-generated suggestion
 * (C-2105, C-2106, ...) but stays editable — see CustomerService::create()/
 * peekNextCode(). 2026_09_11_000002 corrected the series real production
 * data needs: prefix "C-" (not "CUST-") starting after the existing C-0001..
 * C-2104 range, not a fresh count-based guess.
 */
class CustomerCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026_09_11_000001 + _000002 already seed+correct the 'customer' NamingSeries
        // to prefix "C-", current_number = 2104 (so the first generated code is C-2105).
        foreach (['create', 'view', 'update'] as $action) {
            Permission::query()->firstOrCreate(['name' => "master.customers.{$action}", 'guard_name' => 'web']);
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['master.customers.create', 'master.customers.view', 'master.customers.update']);
        Sanctum::actingAs($user);
    }

    public function test_store_without_a_code_uses_the_generated_suggestion(): void
    {
        $first = $this->postJson('/api/v1/customers', ['customer_name' => 'Acme'])->assertCreated();
        $this->assertSame('C-2105', $first->json('data.customer_code'));

        $second = $this->postJson('/api/v1/customers', ['customer_name' => 'Beta'])->assertCreated();
        $this->assertSame('C-2106', $second->json('data.customer_code'));
    }

    public function test_store_honors_a_client_supplied_code(): void
    {
        $response = $this->postJson('/api/v1/customers', ['customer_name' => 'Acme', 'customer_code' => 'CUSTOM-01'])
            ->assertCreated();
        $this->assertSame('CUSTOM-01', $response->json('data.customer_code'));

        // Duplicate customer_code is still rejected either way.
        $this->postJson('/api/v1/customers', ['customer_name' => 'Beta', 'customer_code' => 'CUSTOM-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_code');
    }

    public function test_overriding_the_code_still_advances_the_counter_so_the_next_suggestion_moves_on(): void
    {
        $this->postJson('/api/v1/customers', ['customer_name' => 'Acme', 'customer_code' => 'CUSTOM-01'])->assertCreated();

        // The next suggestion is C-2106, not the still-unused C-2105 — otherwise a second
        // customer created without a code would collide with what the first one could have had.
        $this->getJson('/api/v1/customers/next-code')->assertOk()->assertJsonPath('data.customer_code', 'C-2106');
    }

    public function test_next_code_previews_without_consuming_the_counter(): void
    {
        $this->getJson('/api/v1/customers/next-code')->assertOk()->assertJsonPath('data.customer_code', 'C-2105');
        // Calling it again without creating anything must not have advanced the counter.
        $this->getJson('/api/v1/customers/next-code')->assertOk()->assertJsonPath('data.customer_code', 'C-2105');

        $this->postJson('/api/v1/customers', ['customer_name' => 'Acme'])->assertCreated();

        $this->getJson('/api/v1/customers/next-code')->assertOk()->assertJsonPath('data.customer_code', 'C-2106');
    }

    public function test_update_can_change_customer_code(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C-2105', 'customer_name' => 'Acme']);

        $this->putJson("/api/v1/customers/{$customer->id}", ['customer_name' => 'Acme', 'customer_code' => 'C-2105-FIXED'])
            ->assertOk();

        $this->assertSame('C-2105-FIXED', $customer->fresh()->customer_code);
    }

    public function test_update_rejects_a_duplicate_customer_code(): void
    {
        Customer::query()->create(['customer_code' => 'C-2105', 'customer_name' => 'Acme']);
        $other = Customer::query()->create(['customer_code' => 'C-2106', 'customer_name' => 'Beta']);

        $this->putJson("/api/v1/customers/{$other->id}", ['customer_name' => 'Beta', 'customer_code' => 'C-2105'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_code');
    }

    public function test_series_correction_starts_after_the_real_existing_range(): void
    {
        // Simulates the actual production shape this correction targets: C-0001..C-2104 already exist.
        NamingSeries::query()->where('document_type', 'customer')->delete();
        Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Legacy One']);
        Customer::query()->create(['customer_code' => 'C-2104', 'customer_name' => 'Legacy Last']);

        NamingSeries::query()->create([
            'module' => 'master', 'document_type' => 'customer', 'prefix' => 'C-',
            'digit_length' => 4, 'current_number' => 2104, 'is_default' => true, 'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/customers', ['customer_name' => 'New Co'])->assertCreated();
        $this->assertSame('C-2105', $response->json('data.customer_code'));
    }
}
