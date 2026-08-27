<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\SalesPerson;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The Sales module's new advanced-filter contract (multi-select status,
 * sales_person_id) and bulk export (SalesOrderController::export) — the
 * pilot module the other 4 Sales list pages' identical wiring is copied
 * from. See app/Http/Controllers/Concerns/ExportsSalesList.php.
 */
class SalesOrderFilterExportTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'sales.orders.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.orders.view');
        Sanctum::actingAs($user);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
    }

    protected function makeOrder(string $status, ?SalesPerson $salesPerson = null): SalesOrder
    {
        return SalesOrder::query()->create([
            'status' => $status,
            'customer_id' => $this->customer->id,
            'sales_person_id' => $salesPerson?->id,
            'order_date' => now()->toDateString(),
            'total_amount' => 100000,
            'grand_total' => 100000,
        ]);
    }

    public function test_status_filter_accepts_multiple_values(): void
    {
        $submitted = $this->makeOrder('submitted');
        $approved = $this->makeOrder('approved');
        $this->makeOrder('cancelled');

        $response = $this->getJson('/api/v1/sales-orders?'.http_build_query(['status' => ['submitted', 'approved']], '', '&'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertEquals(collect([$submitted->id, $approved->id])->sort()->values()->all(), $ids);
    }

    public function test_legacy_singular_status_param_still_works(): void
    {
        $submitted = $this->makeOrder('submitted');
        $this->makeOrder('approved');

        $response = $this->getJson('/api/v1/sales-orders?status=submitted');

        $response->assertOk();
        $this->assertEquals([$submitted->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_sales_person_filter_narrows_results(): void
    {
        $salesPersonA = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Alice']);
        $salesPersonB = SalesPerson::query()->create(['code' => 'SP2', 'name' => 'Bob']);

        $orderA = $this->makeOrder('submitted', $salesPersonA);
        $this->makeOrder('submitted', $salesPersonB);

        $response = $this->getJson("/api/v1/sales-orders?sales_person_id={$salesPersonA->id}");

        $response->assertOk();
        $this->assertEquals([$orderA->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_export_respects_filters_and_downloads_expected_row_count(): void
    {
        Excel::fake();

        $this->makeOrder('submitted');
        $this->makeOrder('approved');
        $this->makeOrder('cancelled');

        $this->get('/api/v1/sales-orders/export?format=xlsx&'.http_build_query(['status' => ['submitted', 'approved']], '', '&'))
            ->assertOk();

        Excel::assertDownloaded('sales_orders_'.now()->toDateString().'_'.now()->toDateString().'.xlsx', function ($export) {
            return $export->collection()->count() === 2;
        });
    }

    public function test_export_ids_override_the_active_filter(): void
    {
        Excel::fake();

        $submitted = $this->makeOrder('submitted');
        $this->makeOrder('approved');

        // status=cancelled matches nothing, but ids[] should win outright and still return the row.
        $this->get('/api/v1/sales-orders/export?format=xlsx&status[]=cancelled&ids[]='.$submitted->id)
            ->assertOk();

        Excel::assertDownloaded('sales_orders_'.now()->toDateString().'_'.now()->toDateString().'.xlsx', function ($export) use ($submitted) {
            return $export->collection()->count() === 1 && $export->collection()->first()->id === $submitted->id;
        });
    }

    public function test_export_column_selection_narrows_the_output_columns(): void
    {
        Excel::fake();

        $this->makeOrder('submitted');

        $this->get('/api/v1/sales-orders/export?format=xlsx&columns[]=document_number&columns[]=status')
            ->assertOk();

        Excel::assertDownloaded('sales_orders_'.now()->toDateString().'_'.now()->toDateString().'.xlsx', function ($export) {
            return $export->headings() === ['Document', 'Status'];
        });
    }

    public function test_export_with_no_matching_data_returns_an_informative_error(): void
    {
        $response = $this->getJson('/api/v1/sales-orders/export?format=xlsx&status[]=cancelled');

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Tidak ada data untuk diekspor.']);
    }
}
