<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Journal List's Sales Journal tab — screen data (SalesJournalController::index()), document-level, reusing SalesListingRow's shape. */
class SalesJournalTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        Permission::query()->firstOrCreate(['name' => 'accounting.journal_list.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.journal_list.view');
        Sanctum::actingAs($user);
    }

    protected function makeInvoice(float $amount, array $overrides = []): Invoice
    {
        $date = $overrides['invoice_date'] ?? now()->toDateString();

        return Invoice::query()->create(array_merge([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $this->customer->id,
            'invoice_date' => $date, 'due_date' => $date,
            'subtotal' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $amount,
        ], $overrides));
    }

    protected function makeCreditNote(Invoice $invoice, float $amount): CreditNote
    {
        return CreditNote::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $this->customer->id, 'credit_note_date' => now()->toDateString(),
            'reason' => 'returned_goods', 'status' => 'submitted', 'subtotal' => $amount, 'discount_amount' => 0,
            'tax_amount' => 0, 'total_amount' => $amount,
        ]);
    }

    public function test_default_view_lists_invoices(): void
    {
        $invoice = $this->makeInvoice(100000);

        $response = $this->get('/api/v1/sales-journal')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($invoice->document_number, $response->json('data.0.document_number'));
        $this->assertEquals('invoice', $response->json('data.0.type'));
    }

    public function test_credit_note_view_lists_only_credit_notes(): void
    {
        $invoice = $this->makeInvoice(100000);
        $this->makeCreditNote($invoice, 30000);

        $response = $this->get('/api/v1/sales-journal?view=credit_note')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('credit_note', $rows[0]['type']);
    }

    public function test_date_range_filter_narrows_the_list(): void
    {
        $this->makeInvoice(100000, ['invoice_date' => '2026-01-15', 'due_date' => '2026-01-15']);
        $this->makeInvoice(50000, ['invoice_date' => '2026-02-15', 'due_date' => '2026-02-15']);

        $response = $this->get('/api/v1/sales-journal?date_from=2026-01-01&date_to=2026-01-31')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }
}
