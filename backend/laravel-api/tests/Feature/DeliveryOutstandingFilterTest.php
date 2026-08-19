<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Point 4B — the /deliveries?outstanding=1 filter surfaces the same predicate the New
 * Invoice flow already uses to pick eligible deliveries (complete + not yet invoiced).
 */
class DeliveryOutstandingFilterTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => \App\Enums\WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        app(\App\Services\StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: \App\Enums\StockTransactionType::IN,
            voucherType: \App\Enums\StockVoucherType::STOCK_IN,
            voucherId: (string) \Illuminate\Support\Str::uuid(),
            qtyChange: 1000,
            postingDatetime: now(),
        );

        Permission::query()->firstOrCreate(['name' => 'sales.deliveries.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('sales.deliveries.view');
        Sanctum::actingAs($viewer);
    }

    protected function newDelivery(int $qty = 10): Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => 10000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        return $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
    }

    public function test_complete_not_yet_invoiced_delivery_appears_under_outstanding(): void
    {
        $delivery = $this->deliveryService->complete($this->newDelivery());

        $response = $this->getJson('/api/v1/deliveries?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertTrue($documentNumbers->contains($delivery->fresh()->document_number));
    }

    public function test_invoiced_delivery_is_excluded(): void
    {
        $delivery = $this->deliveryService->complete($this->newDelivery());
        $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $response = $this->getJson('/api/v1/deliveries?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertFalse($documentNumbers->contains($delivery->fresh()->document_number));
    }

    public function test_still_pending_delivery_is_excluded(): void
    {
        $delivery = $this->newDelivery();

        $response = $this->getJson('/api/v1/deliveries?outstanding=1');

        $response->assertOk();
        $documentNumbers = collect($response->json('data'))->pluck('document_number');
        $this->assertFalse($documentNumbers->contains($delivery->fresh()->document_number));
    }

    /**
     * Regression: IndexDeliveryRequest validated `status` against the generic DocumentStatus
     * enum (draft/submitted/cancelled) instead of Delivery's own DeliveryStatus (pending/
     * complete) — every status-filtered request 422'd ("The selected status is invalid."),
     * including New Invoice's own eligible-deliveries fetch and this list's Status filter.
     */
    public function test_status_filter_accepts_delivery_specific_enum_values(): void
    {
        $pending = $this->newDelivery();
        $complete = $this->deliveryService->complete($this->newDelivery());

        $completeResponse = $this->getJson('/api/v1/deliveries?status=complete');
        $completeResponse->assertOk();
        $completeNumbers = collect($completeResponse->json('data'))->pluck('document_number');
        $this->assertTrue($completeNumbers->contains($complete->fresh()->document_number));
        $this->assertFalse($completeNumbers->contains($pending->fresh()->document_number));

        $pendingResponse = $this->getJson('/api/v1/deliveries?status=pending');
        $pendingResponse->assertOk();
        $pendingNumbers = collect($pendingResponse->json('data'))->pluck('document_number');
        $this->assertTrue($pendingNumbers->contains($pending->fresh()->document_number));
        $this->assertFalse($pendingNumbers->contains($complete->fresh()->document_number));
    }

    /** Regression: is_invoiced used to be false (not null) while still Pending, misleadingly implying "eligible to invoice now" on the list's status badge before the Delivery is even Complete. */
    public function test_is_invoiced_is_null_while_pending(): void
    {
        $delivery = $this->newDelivery();

        $response = $this->getJson('/api/v1/deliveries');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('document_number', $delivery->fresh()->document_number);
        $this->assertNull($row['is_invoiced']);
    }

    public function test_outstanding_filter_query_count_does_not_scale_with_row_count(): void
    {
        $this->deliveryService->complete($this->newDelivery());
        // Warm-up request first — the very first authenticated request in a test process pays a
        // one-time Spatie permission-cache query that has nothing to do with row count; without
        // this warm-up it would look like a false N+1 signal.
        $this->getJson('/api/v1/deliveries?outstanding=1')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/v1/deliveries?outstanding=1')->assertOk();
        $queriesForOne = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 4; $i++) {
            $this->deliveryService->complete($this->newDelivery());
        }
        DB::enableQueryLog();
        $this->getJson('/api/v1/deliveries?outstanding=1')->assertOk();
        $queriesForFive = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesForOne, $queriesForFive, 'Query count must stay flat as row count grows (no N+1).');
    }
}
