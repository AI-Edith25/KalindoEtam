<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\PriceZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Customer.price_zone_id: nullable assignment, and nulling out on zone delete (fallback to standard_rate downstream). */
class CustomerPriceZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view', 'create', 'update'] as $action) {
            Permission::query()->firstOrCreate(['name' => "master.customers.{$action}", 'guard_name' => 'web']);
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['master.customers.view', 'master.customers.create', 'master.customers.update']);
        Sanctum::actingAs($user);
    }

    public function test_customer_can_be_created_without_a_price_zone(): void
    {
        $response = $this->postJson('/api/v1/customers', ['customer_code' => 'CUST-1', 'customer_name' => 'Toko Jaya']);

        $response->assertCreated();
        $this->assertNull($response->json('data.price_zone_id'));
    }

    public function test_customer_can_be_assigned_a_price_zone(): void
    {
        $zone = PriceZone::query()->create(['name' => 'Balikpapan']);

        $response = $this->postJson('/api/v1/customers', [
            'customer_code' => 'CUST-2', 'customer_name' => 'Toko Makmur', 'price_zone_id' => $zone->id,
        ]);

        $response->assertCreated();
        $this->assertSame($zone->id, $response->json('data.price_zone_id'));
    }

    public function test_deleting_a_price_zone_nulls_out_customers_using_it_instead_of_failing(): void
    {
        $zone = PriceZone::query()->create(['name' => 'Balikpapan']);
        $customer = Customer::query()->create(['customer_code' => 'CUST-3', 'customer_name' => 'Toko Sentosa', 'price_zone_id' => $zone->id]);

        $zone->forceDelete();

        $this->assertNull($customer->fresh()->price_zone_id);
    }
}
