<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Fleet;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\DriverCommissionRateService;
use App\Services\DriverCommissionReportService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverCommissionReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected DeliveryService $deliveryService;

    protected DriverCommissionRateService $driverCommissionRateService;

    protected DriverCommissionReportService $driverCommissionReportService;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Item $item;

    protected Fleet $fleet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->driverCommissionRateService = app(DriverCommissionRateService::class);
        $this->driverCommissionReportService = app(DriverCommissionReportService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'Semen']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create(['item_code' => 'SMN-1', 'item_name' => 'Semen Conch PCC 50 KG', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000]);

        $this->fleet = Fleet::query()->create(['plat_number' => 'KT8655NV', 'default_driver_name' => 'Dani']);

        $this->seedStock($this->item->id, $this->warehouse->id, 1000);

        $this->driverCommissionRateService->create(['fleet_id' => $this->fleet->id, 'item_id' => $this->item->id, 'rate_per_zak' => 1100, 'effective_date' => '2026-01-01']);
    }

    protected function completedDelivery(string $deliveryDate, int $qty = 100, string $deliveryStatus = 'antar_dengan_buruh', ?string $fleetId = 'use-default'): Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => $deliveryDate,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => 60000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => $deliveryDate,
            'due_date' => now()->addDays(30)->toDateString(),
            'driver' => 'Dani',
            'fleet_id' => $fleetId === 'use-default' ? $this->fleet->id : $fleetId,
            'delivery_status' => $deliveryStatus,
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);

        return $this->deliveryService->complete($delivery);
    }

    public function test_a_delivery_with_a_matching_rate_appears_with_the_computed_commission(): void
    {
        $this->completedDelivery('2026-09-10', 100);

        $rows = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);

        $this->assertCount(1, $rows);
        $this->assertEquals(100, $rows->first()->qty);
        $this->assertEquals(1100, $rows->first()->rate_per_zak);
        $this->assertEquals(110000, $rows->first()->total_commission);
    }

    public function test_a_delivery_with_no_fleet_is_excluded(): void
    {
        $this->completedDelivery('2026-09-10', 100, 'antar_dengan_buruh', null);

        $rows = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);

        $this->assertCount(0, $rows);
    }

    public function test_a_delivery_before_any_rate_took_effect_is_excluded_not_errored(): void
    {
        $this->completedDelivery('2025-01-01', 100);

        $rows = $this->driverCommissionReportService->rows(['date_from' => '2024-01-01', 'date_to' => '2025-12-31']);

        $this->assertCount(0, $rows);
    }

    public function test_delivery_status_filter_defaults_to_delivered_with_labor(): void
    {
        $this->completedDelivery('2026-09-10', 100, 'antar_dengan_buruh');
        $this->completedDelivery('2026-09-11', 50, 'antar_tanpa_buruh');

        $defaultOnly = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
        $withoutLaborOnly = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'delivery_status' => 'antar_tanpa_buruh']);
        $all = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'delivery_status' => 'all']);

        $this->assertCount(1, $defaultOnly);
        $this->assertEquals(100, $defaultOnly->first()->qty);
        $this->assertCount(1, $withoutLaborOnly);
        $this->assertEquals(50, $withoutLaborOnly->first()->qty);
        $this->assertCount(2, $all);
    }

    public function test_antar_tanpa_buruh_computes_commission_the_same_way_as_antar_dengan_buruh(): void
    {
        $this->completedDelivery('2026-09-10', 100, 'antar_tanpa_buruh');

        $rows = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'delivery_status' => 'antar_tanpa_buruh']);

        $this->assertCount(1, $rows);
        $this->assertEquals(1100, $rows->first()->rate_per_zak);
        $this->assertEquals(110000, $rows->first()->total_commission);
    }

    public function test_muat_gudang_appears_with_no_commission_and_is_excluded_from_totals(): void
    {
        $this->completedDelivery('2026-09-10', 100, 'muat_gudang', null);

        $rows = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'delivery_status' => 'all']);
        $summary = $this->driverCommissionReportService->summary($rows);

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->rate_per_zak);
        $this->assertNull($rows->first()->total_commission);
        $this->assertEquals(0, $summary['total_commission']);
        $this->assertEquals(100, $summary['total_qty']);
    }

    public function test_antar_proyek_never_looks_up_a_rate_even_when_one_matches(): void
    {
        // Same fleet+item pair the setUp rate was configured for — proves the rate lookup is
        // skipped entirely for this status, not just "not found".
        $this->completedDelivery('2026-09-10', 100, 'antar_proyek');

        $rows = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'delivery_status' => 'all']);

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->rate_per_zak);
        $this->assertNull($rows->first()->total_commission);
    }

    public function test_summary_totals_and_per_driver_subtotal(): void
    {
        $this->completedDelivery('2026-09-10', 100);
        $this->completedDelivery('2026-09-11', 50);

        $rows = $this->driverCommissionReportService->rows(['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
        $summary = $this->driverCommissionReportService->summary($rows);

        $this->assertEquals(150, $summary['total_qty']);
        $this->assertEquals(165000, $summary['total_commission']);
        $this->assertCount(1, $summary['by_driver']);
        $this->assertEquals('Dani', $summary['by_driver'][0]['driver']);
        $this->assertEquals(165000, $summary['by_driver'][0]['total_commission']);
    }

    public function test_date_range_filter_excludes_deliveries_outside_it(): void
    {
        $this->completedDelivery('2026-09-10', 100);

        $rows = $this->driverCommissionReportService->rows(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);

        $this->assertCount(0, $rows);
    }

    public function test_commissionable_items_lists_only_items_with_a_driver_commission_rate(): void
    {
        $itemGroup = ItemGroup::query()->first();
        $uom = UnitOfMeasurement::query()->first();
        Item::query()->create(['item_code' => 'NAIL-1', 'item_name' => 'Paku Tokka', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000]);

        $items = $this->driverCommissionReportService->commissionableItems();

        $this->assertCount(1, $items);
        $this->assertEquals($this->item->id, $items->first()->id);
    }
}
