<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use App\Models\Company;
use App\Models\CreditNote;
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

/** Sales Listing tab — one row per Invoice or Credit Note document. */
class SalesListingTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.sales.view');
        Sanctum::actingAs($user);
    }

    /** A full SalesOrder->Delivery->Invoice chain plus its AccountsReceivable row, direct Eloquent creates. */
    protected function makeInvoice(float $amount, string $paymentStatus = 'unpaid', float $paidAmount = 0, array $overrides = []): Invoice
    {
        $warehouse = Warehouse::query()->firstOrCreate(['code' => 'WH1'], ['name' => 'Main WH', 'warehouse_type' => 'main']);
        $salesOrder = SalesOrder::query()->create(['customer_id' => $this->customer->id, 'status' => 'approved', 'order_date' => now()->toDateString()]);
        $delivery = Delivery::query()->create([
            'sales_order_id' => $salesOrder->id, 'customer_id' => $this->customer->id, 'warehouse_id' => $warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'status' => 'complete',
        ]);
        $date = $overrides['invoice_date'] ?? now()->toDateString();
        $invoice = Invoice::query()->create(array_merge([
            'invoice_type' => 'goods', 'status' => 'submitted', 'delivery_id' => $delivery->id, 'sales_order_id' => $salesOrder->id,
            'customer_id' => $this->customer->id, 'invoice_date' => $date, 'due_date' => $date,
            'subtotal' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $amount,
        ], $overrides));

        AccountsReceivable::query()->create([
            'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'sales_order_id' => $salesOrder->id, 'delivery_id' => $delivery->id,
            'reference_number' => $invoice->document_number, 'amount' => $amount, 'paid_amount' => $paidAmount,
            'due_date' => now()->addDays(30)->toDateString(), 'status' => AccountsReceivableStatus::from($paymentStatus),
        ]);

        return $invoice;
    }

    protected function makeCreditNote(Invoice $invoice, float $amount, bool $isReversed = false): CreditNote
    {
        return CreditNote::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $this->customer->id, 'credit_note_date' => now()->toDateString(),
            'reason' => 'returned_goods', 'status' => 'submitted', 'subtotal' => $amount, 'discount_amount' => 0,
            'tax_amount' => 0, 'total_amount' => $amount, 'is_reversed' => $isReversed,
        ]);
    }

    public function test_invoice_row_shows_positive_amounts_and_reference_so_do(): void
    {
        $invoice = $this->makeInvoice(100000);

        $response = $this->get('/api/v1/reports/sales/listing')->assertOk();

        $row = $response->json('data.0');
        $this->assertEquals('invoice', $row['type']);
        $this->assertEquals(100000, $row['amount']);
        $this->assertEquals(100000, $row['amount_incl_tax']);
        $this->assertEquals($invoice->salesOrder->document_number, $row['reference_so_number']);
        $this->assertEquals($invoice->delivery->document_number, $row['reference_do_number']);
        $this->assertEquals('unpaid', $row['payment_status']);
        $this->assertEquals(100000, $row['outstanding_ar']);
    }

    public function test_credit_note_row_shows_negative_amounts(): void
    {
        $invoice = $this->makeInvoice(100000);
        $this->makeCreditNote($invoice, 30000);

        $response = $this->get('/api/v1/reports/sales/listing?type=credit_note')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('credit_note', $rows[0]['type']);
        $this->assertEquals(-30000, $rows[0]['amount']);
        $this->assertEquals(-30000, $rows[0]['amount_incl_tax']);
        $this->assertNull($rows[0]['payment_status']);
    }

    public function test_reversed_credit_note_is_excluded(): void
    {
        $invoice = $this->makeInvoice(100000);
        $this->makeCreditNote($invoice, 30000, isReversed: true);

        $response = $this->get('/api/v1/reports/sales/listing?type=credit_note')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_draft_invoice_is_excluded(): void
    {
        $this->makeInvoice(100000, overrides: ['status' => 'draft']);

        $response = $this->get('/api/v1/reports/sales/listing')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_kpi_net_sales_nets_credit_notes_against_invoices_over_the_full_filtered_set(): void
    {
        $invoiceA = $this->makeInvoice(100000);
        $this->makeCreditNote($invoiceA, 20000);
        $this->makeInvoice(50000);
        $this->makeInvoice(1000);

        $response = $this->get('/api/v1/reports/sales/listing?per_page=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(4, $response->json('meta.last_page')); // 3 invoices + 1 credit note = 4 documents

        $kpis = $response->json('meta.kpis');
        $this->assertEquals(100000 - 20000 + 50000 + 1000, $kpis['net_sales']);
        $this->assertEquals(3, $kpis['invoice_count']); // credit notes don't count as invoices
    }

    public function test_paid_vs_unpaid_kpi_splits_by_accounts_receivable_status(): void
    {
        $this->makeInvoice(100000, 'paid', 100000);
        $this->makeInvoice(50000, 'unpaid', 0);

        $response = $this->get('/api/v1/reports/sales/listing')->assertOk();

        $kpis = $response->json('meta.kpis');
        $this->assertEquals(100000, $kpis['paid_value']);
        $this->assertEquals(50000, $kpis['unpaid_value']);
    }

    public function test_date_range_filter_narrows_the_listing(): void
    {
        $this->makeInvoice(100000, overrides: ['invoice_date' => '2026-01-15', 'due_date' => '2026-01-15']);
        $this->makeInvoice(50000, overrides: ['invoice_date' => '2026-02-15', 'due_date' => '2026-02-15']);

        $response = $this->get('/api/v1/reports/sales/listing?date_from=2026-01-01&date_to=2026-01-31')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(100000, $response->json('data.0.amount'));
    }
}
