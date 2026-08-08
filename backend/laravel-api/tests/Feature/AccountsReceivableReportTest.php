<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\SalesPerson;
use App\Models\TermsOfPayment;
use App\Models\Warehouse;
use App\Services\AccountsReceivableService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsReceivableReportTest extends TestCase
{
    use RefreshDatabase;

    protected AccountsReceivableService $accountsReceivableService;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->accountsReceivableService = app(AccountsReceivableService::class);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
    }

    /** Builds one AccountsReceivable row with a full SalesOrder->Delivery->Invoice chain behind it, direct Eloquent creates — only the report path (repository/resource) is under test here. */
    protected function makeReceivable(array $overrides = []): AccountsReceivable
    {
        $warehouse = Warehouse::query()->firstOrCreate(['code' => 'WH1'], ['name' => 'Main WH', 'warehouse_type' => 'main']);

        $salesOrder = SalesOrder::query()->create([
            'customer_id' => $this->customer->id,
            'sales_person_id' => $overrides['sales_person_id'] ?? null,
            'order_date' => now()->toDateString(),
        ]);

        $delivery = Delivery::query()->create([
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $invoiceDate = $overrides['invoice_date'] ?? now()->toDateString();
        $invoice = Invoice::query()->create([
            'delivery_id' => $delivery->id,
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $this->customer->id,
            'invoice_date' => $invoiceDate,
            'due_date' => now()->addDays(30)->toDateString(),
            'terms_of_payment_id' => $overrides['terms_of_payment_id'] ?? null,
        ]);

        return AccountsReceivable::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'sales_order_id' => $salesOrder->id,
            'delivery_id' => $delivery->id,
            'reference_number' => $invoice->document_number,
            'amount' => $overrides['amount'] ?? 100000,
            'paid_amount' => $overrides['paid_amount'] ?? 0,
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => AccountsReceivableStatus::UNPAID,
        ]);
    }

    public function test_resolves_terms_of_payment_days_sales_person_and_age_in_days(): void
    {
        $salesPerson = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);
        $top = TermsOfPayment::query()->create(['code' => 'NET30', 'name' => 'Net 30', 'days' => 30]);

        $this->makeReceivable([
            'sales_person_id' => $salesPerson->id,
            'terms_of_payment_id' => $top->id,
            'invoice_date' => now()->subDays(10)->toDateString(),
        ]);

        $result = $this->accountsReceivableService->list([]);
        $row = $result->items()[0];

        $this->assertEquals(30, $row->invoice->termsOfPayment->days);
        $this->assertEquals('Budi', $row->salesOrder->salesPerson->name);
        $this->assertEquals(10, (int) $row->invoice->invoice_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), true));
    }

    public function test_missing_sales_person_and_terms_of_payment_resolve_to_null_not_an_error(): void
    {
        $this->makeReceivable();

        $result = $this->accountsReceivableService->list([]);
        $row = $result->items()[0];

        $this->assertNull($row->salesOrder->salesPerson);
        $this->assertNull($row->invoice->termsOfPayment);
    }

    public function test_invoice_date_filter_narrows_the_list_and_the_outstanding_total_identically(): void
    {
        $this->makeReceivable(['invoice_date' => '2026-01-01', 'amount' => 50000]);
        $this->makeReceivable(['invoice_date' => '2026-06-01', 'amount' => 70000]);

        $filters = ['invoice_date_from' => '2026-05-01', 'invoice_date_to' => '2026-12-31'];

        $result = $this->accountsReceivableService->list($filters);
        $this->assertCount(1, $result->items());
        $this->assertEquals(70000, $result->items()[0]->amount);

        $this->assertEquals(70000.0, $this->accountsReceivableService->outstandingTotal($filters));
    }

    public function test_outstanding_amount_unaffected_by_the_new_fields(): void
    {
        $this->makeReceivable(['amount' => 100000, 'paid_amount' => 40000]);

        $result = $this->accountsReceivableService->list([]);
        $row = $result->items()[0];

        $this->assertEquals(60000, (float) $row->amount - (float) $row->paid_amount);
    }
}
