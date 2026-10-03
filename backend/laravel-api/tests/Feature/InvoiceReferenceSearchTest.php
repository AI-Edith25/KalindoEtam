<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sales > Invoices list's free-text Search field extended to also match
 * reference_1/reference_2 and the originating Delivery's document_number —
 * see InvoiceRepository::applyFilters().
 */
class InvoiceReferenceSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'sales.invoices.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.invoices.view');
        Sanctum::actingAs($user);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => \App\Enums\WarehouseType::MAIN]);
    }

    protected function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'invoice_type' => 'goods',
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'subtotal' => 100000,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => 100000,
            'source' => 'manual',
        ], $overrides));
    }

    public function test_search_matches_reference_1(): void
    {
        $matching = $this->makeInvoice(['reference_1' => 'PO-7788']);
        $this->makeInvoice(['reference_1' => 'PO-1111']);

        $response = $this->getJson('/api/v1/invoices?search=7788');

        $response->assertOk();
        $this->assertEquals([$matching->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_search_matches_reference_2(): void
    {
        $matching = $this->makeInvoice(['reference_2' => 'SJ-4242']);
        $this->makeInvoice(['reference_2' => 'SJ-9999']);

        $response = $this->getJson('/api/v1/invoices?search=4242');

        $response->assertOk();
        $this->assertEquals([$matching->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_search_matches_the_source_deliverys_document_number(): void
    {
        $salesOrder = SalesOrder::query()->create([
            'status' => 'approved',
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'total_amount' => 100000,
            'grand_total' => 100000,
        ]);

        $delivery = Delivery::query()->create([
            'status' => 'pending',
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $matching = $this->makeInvoice();
        $matching->deliveries()->attach($delivery->id);
        $this->makeInvoice();

        $response = $this->getJson('/api/v1/invoices?search='.$delivery->document_number);

        $response->assertOk();
        $this->assertEquals([$matching->id], collect($response->json('data'))->pluck('id')->all());
    }
}
