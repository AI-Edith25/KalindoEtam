<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CustomerController::index() defaults to per_page=200 with no explicit ordering
 * (CustomerRepository::paginate()). Once total customers exceed that cap, a
 * customer created after the first 200 falls off page 1 with no ordering to
 * guarantee it surfaces — which is exactly what the Sales Order "Customer"
 * dropdown (fetchCustomersLookup, unpaginated per_page) renders from.
 */
class CustomerLookupOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.customers.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['master.customers.view']);
        Sanctum::actingAs($user);
    }

    public function test_a_customer_created_after_the_default_page_cap_still_appears_in_the_lookup(): void
    {
        // Spaced-out created_at, like real customers entered over time (not all in the same request).
        for ($i = 1; $i <= 200; $i++) {
            Customer::query()->forceCreate([
                'customer_code' => "C-FILLER-{$i}",
                'customer_name' => "Filler {$i}",
                'created_at' => now()->subDays(200 - $i),
            ]);
        }

        Customer::query()->forceCreate(['customer_code' => 'C-2111', 'customer_name' => 'RECTA CONSTRUCTION', 'created_at' => now()]);

        $response = $this->getJson('/api/v1/customers')->assertOk();

        $this->assertContains('C-2111', collect($response->json('data'))->pluck('customer_code'));
    }
}
