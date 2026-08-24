<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\SalesPerson;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Customer Sales tab — one row per customer, aggregated over invoice_items via a real DB SUM/GROUP BY. */
class CustomerSalesTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $itemGroup = ItemGroup::query()->create(['name' => 'Hardware']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000]);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    protected function invoicedLine(Customer $customer, int $qty, float $rate, array $overrides = []): Invoice
    {
        $amount = $qty * $rate;
        $date = $overrides['invoice_date'] ?? now()->toDateString();
        $invoice = Invoice::query()->create(array_merge([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $customer->id,
            'invoice_date' => $date, 'due_date' => $date, 'subtotal' => $amount, 'discount_amount' => 0,
            'tax_amount' => 0, 'grand_total' => $amount,
        ], $overrides));
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id, 'item_id' => $this->item->id, 'item_code' => $this->item->item_code,
            'item_name' => $this->item->item_name, 'uom' => 'PCS', 'rate' => $rate, 'qty' => $qty, 'amount' => $amount, 'tax_amount' => 0,
        ]);

        return $invoice;
    }

    public function test_list_aggregates_multiple_invoices_of_the_same_customer_into_one_row(): void
    {
        $this->invoicedLine($this->customer, 10, 1000);
        $this->invoicedLine($this->customer, 5, 1000);

        $response = $this->get('/api/v1/reports/sales/customers')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(2, $rows[0]['transaction_count']);
        $this->assertEquals(15, $rows[0]['qty']);
        $this->assertEquals(15000, $rows[0]['amount']);
    }

    public function test_kpi_totals_reflect_the_full_filtered_set_not_just_the_loaded_page(): void
    {
        $other1 = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Beta']);
        $other2 = Customer::query()->create(['customer_code' => 'C003', 'customer_name' => 'Gamma']);
        $this->invoicedLine($this->customer, 10, 1000); // 10000
        $this->invoicedLine($other1, 5, 1000); // 5000
        $this->invoicedLine($other2, 1, 500); // 500

        $response = $this->get('/api/v1/reports/sales/customers?per_page=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(3, $response->json('meta.last_page'));

        $kpis = $response->json('meta.kpis');
        $this->assertEquals(3, $kpis['total_customers']);
        $this->assertEquals(15500, $kpis['total_revenue']);
        $this->assertEqualsWithDelta(15500 / 3, $kpis['avg_per_customer'], 0.01);
        $this->assertEquals('Acme', $kpis['top_customer_name']);
    }

    public function test_draft_invoices_are_excluded_by_default(): void
    {
        $this->invoicedLine($this->customer, 10, 1000, ['status' => 'draft']);

        $response = $this->get('/api/v1/reports/sales/customers')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_branch_and_sales_person_show_multiple_when_inconsistent_across_invoices(): void
    {
        $company = Company::query()->create(['name' => 'C2', 'code' => 'C2', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branchA = Branch::query()->create(['company_id' => $company->id, 'name' => 'Branch A', 'code' => 'BA']);
        $branchB = Branch::query()->create(['company_id' => $company->id, 'name' => 'Branch B', 'code' => 'BB']);
        $salesPersonA = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);

        $this->invoicedLine($this->customer, 10, 1000, ['branch_id' => $branchA->id, 'sales_person_id' => $salesPersonA->id]);
        $this->invoicedLine($this->customer, 5, 1000, ['branch_id' => $branchB->id, 'sales_person_id' => $salesPersonA->id]);

        $response = $this->get('/api/v1/reports/sales/customers')->assertOk();

        $row = $response->json('data.0');
        $this->assertNull($row['branch_name']); // Branch A vs Branch B — inconsistent, "Multiple" on the frontend
        $this->assertEquals('Budi', $row['sales_person_name']); // same sales person both times — consistent
    }

    public function test_documents_drilldown_lists_every_invoice_with_a_subtotal(): void
    {
        $this->invoicedLine($this->customer, 10, 1000);
        $this->invoicedLine($this->customer, 5, 2000);

        $response = $this->get("/api/v1/reports/sales/customers/{$this->customer->id}/documents")->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data['documents']);
        $this->assertEquals(20000, $data['subtotal']['amount']); // 10000 + 10000
    }

    public function test_achievement_groups_by_sales_person(): void
    {
        $salesPerson = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);
        $this->invoicedLine($this->customer, 10, 1000, ['sales_person_id' => $salesPerson->id]);
        $this->invoicedLine($this->customer, 5, 1000); // unassigned

        $response = $this->get('/api/v1/reports/sales/achievement')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertEquals(10000, $rows->firstWhere('sales_person_name', 'Budi')['amount']);
        $this->assertEquals(5000, $rows->firstWhere('sales_person_name', 'Unassigned')['amount']);
    }
}
