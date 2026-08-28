<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DeliveryListPage's new "Sales Order Reference" filter and the free-text
 * Search field's extension to also match the originating Sales Order's
 * document_number — see DeliveryRepository::applyFilters().
 */
class DeliverySalesOrderReferenceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'sales.deliveries.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.deliveries.view');
        Sanctum::actingAs($user);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => \App\Enums\WarehouseType::MAIN]);
    }

    protected function makeDelivery(string $soDocumentNumber): Delivery
    {
        $salesOrder = SalesOrder::query()->create([
            'document_number' => $soDocumentNumber,
            'status' => 'approved',
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'total_amount' => 100000,
            'grand_total' => 100000,
        ]);

        return Delivery::query()->create([
            'status' => 'pending',
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_sales_order_number_filter_narrows_to_the_matching_reference(): void
    {
        $matching = $this->makeDelivery('SO-0001');
        $this->makeDelivery('SO-0002');

        $response = $this->getJson('/api/v1/deliveries?sales_order_number=0001');

        $response->assertOk();
        $this->assertEquals([$matching->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_free_text_search_also_matches_the_sales_order_number(): void
    {
        $matching = $this->makeDelivery('SO-9999');
        $this->makeDelivery('SO-1111');

        $response = $this->getJson('/api/v1/deliveries?search=9999');

        $response->assertOk();
        $this->assertEquals([$matching->id], collect($response->json('data'))->pluck('id')->all());
    }
}
