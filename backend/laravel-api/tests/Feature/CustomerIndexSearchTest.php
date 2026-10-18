<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /master/customers search bug: the frontend used to filter only the loaded 200-row page,
 * so a match sitting on another page read as "not found". The fix moved search (code/name/
 * email) and the status filter into the query — this asserts the server actually does that,
 * independent of pagination.
 */
class CustomerIndexSearchTest extends TestCase
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

    public function test_search_finds_a_customer_outside_the_default_page_size(): void
    {
        for ($i = 1; $i <= 200; $i++) {
            Customer::query()->forceCreate(['customer_code' => "C-FILLER-{$i}", 'customer_name' => "Filler {$i}"]);
        }

        Customer::query()->forceCreate(['customer_code' => 'C-9001', 'customer_name' => 'TK.PUTRA SURYA']);

        $response = $this->getJson('/api/v1/customers?search=putra+surya')->assertOk();

        $codes = collect($response->json('data'))->pluck('customer_code');
        $this->assertContains('C-9001', $codes);
        $this->assertCount(1, $codes);
    }

    public function test_search_matches_email(): void
    {
        Customer::query()->forceCreate(['customer_code' => 'C-9002', 'customer_name' => 'ACME', 'email' => 'buyer@acme.test']);
        Customer::query()->forceCreate(['customer_code' => 'C-9003', 'customer_name' => 'OTHER', 'email' => 'other@example.test']);

        $response = $this->getJson('/api/v1/customers?search=acme.test')->assertOk();

        $this->assertEquals(['C-9002'], collect($response->json('data'))->pluck('customer_code')->all());
    }

    public function test_is_active_filter_combines_with_search(): void
    {
        Customer::query()->forceCreate(['customer_code' => 'C-9004', 'customer_name' => 'ACME ACTIVE', 'is_active' => true]);
        Customer::query()->forceCreate(['customer_code' => 'C-9005', 'customer_name' => 'ACME INACTIVE', 'is_active' => false]);

        $response = $this->getJson('/api/v1/customers?search=acme&is_active=true')->assertOk();

        $this->assertEquals(['C-9004'], collect($response->json('data'))->pluck('customer_code')->all());
    }
}
