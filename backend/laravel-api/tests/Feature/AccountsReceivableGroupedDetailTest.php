<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\SalesPerson;
use App\Services\AccountsReceivableService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** C3 (UAT review 2026-08-12) — "Perincian Piutang": grouped Sales Person -> Customer with subtotals at each level. */
class AccountsReceivableGroupedDetailTest extends TestCase
{
    use RefreshDatabase;

    protected AccountsReceivableService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->service = app(AccountsReceivableService::class);
    }

    protected function receivable(SalesPerson $salesPerson, Customer $customer, float $amount, float $paid, string $dueDate): AccountsReceivable
    {
        $company = Company::query()->firstOrCreate(['code' => 'TC'], ['name' => 'Test Co', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branch = Branch::query()->firstOrCreate(['code' => 'HQ'], ['company_id' => $company->id, 'name' => 'Main']);

        $salesOrder = SalesOrder::query()->create([
            'customer_id' => $customer->id,
            'sales_person_id' => $salesPerson->id,
            'branch_id' => $branch->id,
            'status' => 'submitted',
            'order_date' => now()->toDateString(),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods',
            'status' => 'submitted',
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => $amount,
        ]);

        return AccountsReceivable::query()->create([
            'customer_id' => $customer->id,
            'sales_order_id' => $salesOrder->id,
            'invoice_id' => $invoice->id,
            'reference_number' => $invoice->document_number,
            'amount' => $amount,
            'paid_amount' => $paid,
            'due_date' => $dueDate,
            'status' => $paid >= $amount ? AccountsReceivableStatus::PAID : AccountsReceivableStatus::UNPAID,
        ]);
    }

    public function test_rows_are_grouped_by_sales_person_then_customer_with_subtotals_and_grand_total(): void
    {
        $budi = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);
        $ani = SalesPerson::query()->create(['code' => 'SP2', 'name' => 'Ani']);
        $tokoA = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Toko A']);
        $tokoB = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Toko B']);

        $this->receivable($budi, $tokoA, 100000, 0, now()->addDays(10)->toDateString());
        $this->receivable($budi, $tokoA, 50000, 0, now()->addDays(10)->toDateString());
        $this->receivable($budi, $tokoB, 75000, 0, now()->addDays(10)->toDateString());
        $this->receivable($ani, $tokoA, 20000, 0, now()->addDays(10)->toDateString());

        $result = $this->service->groupedDetail([]);

        $this->assertEquals(245000.0, $result['grand_total']);
        $this->assertCount(2, $result['groups']);

        $budiGroup = $result['groups']->firstWhere('sales_person_name', 'Budi');
        $this->assertEquals(225000.0, $budiGroup['sales_person_subtotal']);
        $this->assertCount(2, $budiGroup['customers']);

        $tokoAUnderBudi = $budiGroup['customers']->firstWhere('customer_name', 'Toko A');
        $this->assertEquals(150000.0, $tokoAUnderBudi['customer_subtotal']);
        $this->assertCount(2, $tokoAUnderBudi['rows']);
        $this->assertEquals($tokoA->id, $tokoAUnderBudi['customer_id']); // needed by the frontend for per-customer checkbox selection
        $this->assertNotNull($tokoAUnderBudi['rows'][0]['invoice_id']); // needed to resolve a customer's checkbox to invoice_ids

        $aniGroup = $result['groups']->firstWhere('sales_person_name', 'Ani');
        $this->assertEquals(20000.0, $aniGroup['sales_person_subtotal']);
    }

    public function test_overdue_days_and_amount_are_zero_for_a_not_yet_due_row_and_positive_once_overdue(): void
    {
        $salesPerson = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Toko A']);

        $this->receivable($salesPerson, $customer, 100000, 0, now()->addDays(10)->toDateString());
        $this->receivable($salesPerson, $customer, 50000, 0, now()->subDays(15)->toDateString());

        $result = $this->service->groupedDetail([]);
        $rows = collect($result['groups']->first()['customers']->first()['rows']);

        $notYetDue = $rows->firstWhere('total_outstanding', 100000.0);
        $overdue = $rows->firstWhere('total_outstanding', 50000.0);

        $this->assertSame(0, $notYetDue['overdue_days']);
        $this->assertEquals(0.0, $notYetDue['overdue_amount']);
        $this->assertSame(15, $overdue['overdue_days']);
        $this->assertEquals(50000.0, $overdue['overdue_amount']);
    }
}
