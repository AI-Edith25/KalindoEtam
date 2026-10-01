<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sales > Invoices list: source filter + source/imported_at in the list response. */
class InvoiceSourceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Permission::query()->firstOrCreate(['name' => 'sales.invoices.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('sales.invoices.view');
        Sanctum::actingAs($viewer);
    }

    private function makeInvoice(string $source, ?string $documentSuffix = null): Invoice
    {
        $warehouse = Warehouse::query()->create(['name' => 'Main WH '.uniqid(), 'code' => 'WH-'.uniqid(), 'warehouse_type' => 'main']);
        $customer = Customer::query()->create(['customer_code' => 'C-'.uniqid(), 'customer_name' => 'Toko '.uniqid()]);

        return Invoice::query()->create([
            'invoice_type' => 'goods',
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'subtotal' => 100000,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => 100000,
            'source' => $source,
            'source_document_number' => $documentSuffix,
        ]);
    }

    public function test_source_filter_returns_only_matching_invoices(): void
    {
        $this->makeInvoice('manual');
        $imported = $this->makeInvoice('import', 'SI/KE/00001/09/2026');

        $response = $this->getJson('/api/v1/invoices?source=import');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$imported->id], $ids->all());
    }

    public function test_list_response_includes_source(): void
    {
        $this->makeInvoice('import', 'SI/KE/00002/09/2026');

        $response = $this->getJson('/api/v1/invoices');

        $response->assertOk();
        $this->assertSame('import', $response->json('data.0.source'));
    }
}
