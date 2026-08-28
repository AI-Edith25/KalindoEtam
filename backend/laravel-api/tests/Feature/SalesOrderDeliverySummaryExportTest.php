<?php

namespace Tests\Feature;

use App\Enums\TaxCalculationMode;
use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\SalesPerson;
use App\Models\Tax;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The new "Summary" export variant (?mode=summary) on Sales Orders and
 * Deliveries — see BuildsSalesSummaryReport, modeled on the real legacy
 * SalesOrderListing_Summary.xlsx / DeliveryOrderListing_Summary.xlsx files.
 */
class SalesOrderDeliverySummaryExportTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'sales.orders.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'sales.deliveries.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.orders.view', 'sales.deliveries.view']);
        Sanctum::actingAs($user);

        $this->customer = Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Acme']);
        $this->tax = Tax::query()->create([
            'code' => 'PPN-K11(EXC)',
            'name' => 'PPN 11%',
            'type' => TaxType::VAT,
            'transaction_type' => TaxTransactionType::SALES,
            'rate' => 11,
            'calculation_mode' => TaxCalculationMode::EXCLUSIVE,
            'is_active' => true,
        ]);
    }

    public function test_sales_order_summary_export_has_correct_headings_and_totals(): void
    {
        Excel::fake();

        $salesPerson = SalesPerson::query()->create(['code' => 'KE-ANTONY', 'name' => 'Antony']);
        SalesOrder::query()->create([
            'status' => 'submitted', 'customer_id' => $this->customer->id, 'sales_person_id' => $salesPerson->id,
            'order_date' => '2026-08-01', 'total_amount' => 100000, 'tax_id' => $this->tax->id, 'tax_amount' => 11000, 'grand_total' => 111000,
        ]);
        SalesOrder::query()->create([
            'status' => 'submitted', 'customer_id' => $this->customer->id, 'sales_person_id' => $salesPerson->id,
            'order_date' => '2026-08-02', 'total_amount' => 50000, 'tax_id' => $this->tax->id, 'tax_amount' => 5500, 'grand_total' => 55500,
        ]);

        $this->get('/api/v1/sales-orders/export?mode=summary&format=xlsx')->assertOk();

        Excel::assertDownloaded('SalesOrderListing_Summary_'.now()->toDateString().'_'.now()->toDateString().'.xlsx', function ($export) {
            $rows = $export->array();
            $this->assertSame('SALES ORDER LISTING - SUMMARY', $rows[0][0]);
            $this->assertSame(['Date', 'Document', 'Customer', 'Customer Name', 'Excl.Tax', 'Disc', 'Tax', 'Incl.Tax', 'Sales Person', 'Delivery Location', 'Branch'], $rows[7]);

            $totalRow = collect($rows)->first(fn ($row) => ($row[3] ?? null) === 'Total By Header');
            $this->assertNotNull($totalRow);
            $this->assertSame(150000.0, $totalRow[4]);
            $this->assertSame(16500.0, $totalRow[6]);
            $this->assertSame(166500.0, $totalRow[7]);

            $taxSummaryHeaderIndex = collect($rows)->search(fn ($row) => ($row[0] ?? null) === 'TAX SUMMARY');
            $this->assertNotFalse($taxSummaryHeaderIndex);
            $taxRow = $rows[$taxSummaryHeaderIndex + 2];
            $this->assertSame('PPN-K11(EXC)', $taxRow[0]);
            $this->assertSame('11 %', $taxRow[1]);

            return true;
        });
    }

    public function test_delivery_summary_export_groups_tax_per_line_and_omits_currency_column(): void
    {
        Excel::fake();

        $warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => \App\Enums\WarehouseType::MAIN]);
        $salesOrder = SalesOrder::query()->create([
            'document_number' => 'SO/KE/0001/08/2026', 'status' => 'approved', 'customer_id' => $this->customer->id,
            'order_date' => '2026-08-01', 'total_amount' => 100000, 'grand_total' => 111000,
        ]);
        $delivery = Delivery::query()->create([
            'status' => 'pending', 'sales_order_id' => $salesOrder->id, 'customer_id' => $this->customer->id,
            'warehouse_id' => $warehouse->id, 'delivery_date' => '2026-08-05', 'due_date' => '2026-09-04',
        ]);
        $item = \App\Models\Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget',
            'item_group_id' => \App\Models\ItemGroup::query()->create(['name' => 'General'])->id,
            'uom_id' => \App\Models\UnitOfMeasurement::query()->create(['name' => 'Pcs'])->id,
            'standard_rate' => 10000,
        ]);
        $salesOrderItem = $salesOrder->items()->create([
            'item_id' => $item->id, 'qty' => 1, 'rate' => 100000, 'amount' => 100000, 'delivered_qty' => 0,
        ]);
        $delivery->items()->create([
            'sales_order_item_id' => $salesOrderItem->id,
            'item_id' => $item->id,
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'uom' => 'Pcs',
            'rate' => 100000, 'qty' => 1, 'amount' => 100000, 'tax_id' => $this->tax->id, 'tax_amount' => 11000,
        ]);

        $this->get('/api/v1/deliveries/export?mode=summary&format=xlsx')->assertOk();

        Excel::assertDownloaded('DeliveryOrderListing_Summary_'.now()->toDateString().'_'.now()->toDateString().'.xlsx', function ($export) {
            $rows = $export->array();
            $this->assertSame('DELIVERY ORDER LISTING - SUMMARY', $rows[0][0]);
            // Currency dropped: 9 columns (no Currency), Reference last.
            $this->assertSame(['Date', 'Document', 'Customer', 'Customer Name', 'Excl.Tax', 'Disc', 'Tax', 'Incl.Tax', 'Reference'], $rows[7]);
            $this->assertSame('SO/KE/0001/08/2026', $rows[8][8]);

            return true;
        });
    }
}
