<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Customer Code (customer_code) is server-generated (CUST-0001, CUST-0002, ...) — see CustomerService::create()/peekNextCode(). */
class CustomerCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 2026_09_11_000001_seed_customer_naming_series migration already seeded the
        // 'customer' NamingSeries (current_number = 0, since this fresh test DB has no customers yet).
        foreach (['create', 'view', 'update'] as $action) {
            Permission::query()->firstOrCreate(['name' => "master.customers.{$action}", 'guard_name' => 'web']);
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['master.customers.create', 'master.customers.view', 'master.customers.update']);
        Sanctum::actingAs($user);
    }

    public function test_store_generates_sequential_codes_and_ignores_client_supplied_ones(): void
    {
        $first = $this->postJson('/api/v1/customers', ['customer_name' => 'Acme'])->assertCreated();
        $this->assertSame('CUST-0001', $first->json('data.customer_code'));

        // A client-supplied customer_code is rejected outright, not silently honored or ignored.
        $this->postJson('/api/v1/customers', ['customer_name' => 'Beta', 'customer_code' => 'HACKED-001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_code');

        $second = $this->postJson('/api/v1/customers', ['customer_name' => 'Beta'])->assertCreated();
        $this->assertSame('CUST-0002', $second->json('data.customer_code'));
    }

    public function test_next_code_previews_without_consuming_the_counter(): void
    {
        $this->getJson('/api/v1/customers/next-code')->assertOk()->assertJsonPath('data.customer_code', 'CUST-0001');
        // Calling it again without creating anything must not have advanced the counter.
        $this->getJson('/api/v1/customers/next-code')->assertOk()->assertJsonPath('data.customer_code', 'CUST-0001');

        $this->postJson('/api/v1/customers', ['customer_name' => 'Acme'])->assertCreated();

        $this->getJson('/api/v1/customers/next-code')->assertOk()->assertJsonPath('data.customer_code', 'CUST-0002');
    }

    public function test_update_cannot_change_customer_code(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'CUST-0001', 'customer_name' => 'Acme']);

        $this->putJson("/api/v1/customers/{$customer->id}", ['customer_name' => 'Acme', 'customer_code' => 'CUST-9999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_code');

        $this->assertSame('CUST-0001', $customer->fresh()->customer_code);
    }

    public function test_new_series_starts_after_pre_existing_customer_count(): void
    {
        // Simulates deploying this feature onto a database that already has legacy-coded customers.
        \App\Models\NamingSeries::query()->where('document_type', 'customer')->delete();
        Customer::query()->create(['customer_code' => 'CUS001', 'customer_name' => 'Legacy One']);
        Customer::query()->create(['customer_code' => 'CUS002', 'customer_name' => 'Legacy Two']);

        \App\Models\NamingSeries::query()->create([
            'module' => 'master', 'document_type' => 'customer', 'prefix' => 'CUST-',
            'digit_length' => 4, 'current_number' => Customer::query()->count(), 'is_default' => true, 'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/customers', ['customer_name' => 'New Co'])->assertCreated();
        $this->assertSame('CUST-0003', $response->json('data.customer_code'));
    }
}
