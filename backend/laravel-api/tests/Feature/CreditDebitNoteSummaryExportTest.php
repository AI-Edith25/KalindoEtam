<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The new "Summary" export variant (?mode=summary) on Credit Notes and Debit
 * Notes — see BuildsSalesSummaryReport. Neither model has a linked Tax
 * record, so the Tax Summary section can only ever produce a single
 * NON-PPN bucket, not a real code/rate breakdown like Sales Orders/Deliveries.
 */
class CreditDebitNoteSummaryExportTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;
    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'sales.credit_notes.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'sales.debit_notes.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.credit_notes.view', 'sales.debit_notes.view']);
        Sanctum::actingAs($user);

        $this->customer = Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Acme']);
        $this->invoice = Invoice::query()->create([
            'invoice_type' => 'goods', 'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 1000000, 'grand_total' => 1000000,
        ]);
    }

    public function test_credit_note_summary_export_has_correct_headings_and_non_ppn_bucket(): void
    {
        Excel::fake();

        CreditNote::query()->create([
            'invoice_id' => $this->invoice->id, 'customer_id' => $this->customer->id,
            'credit_note_date' => '2026-08-10', 'reason' => 'price_adjustment',
            'subtotal' => 60000, 'discount_amount' => 0, 'tax_amount' => 6600, 'total_amount' => 66600,
        ]);

        $this->get('/api/v1/credit-notes/export?mode=summary&format=xlsx')->assertOk();

        Excel::assertDownloaded('CreditNoteToCustomerListing_Summary_'.now()->toDateString().'_'.now()->toDateString().'.xlsx', function ($export) {
            $rows = $export->array();
            $this->assertSame('CREDIT NOTE TO CUSTOMER LISTING - SUMMARY', $rows[0][0]);
            $this->assertSame(['Date', 'Document', 'Customer', 'Customer Name', 'Excl.Tax', 'Disc', 'Tax', 'Incl.Tax'], $rows[7]);

            $taxSummaryHeaderIndex = collect($rows)->search(fn ($row) => ($row[0] ?? null) === 'TAX SUMMARY');
            $taxRow = $rows[$taxSummaryHeaderIndex + 2];
            $this->assertSame('NON-PPN', $taxRow[0]);
            $this->assertSame(6600.0, $taxRow[3]);

            return true;
        });
    }

    public function test_debit_note_summary_export_works_with_zero_rows(): void
    {
        Excel::fake();

        // No Debit Notes at all — the reference legacy file itself has zero data rows and still
        // renders a zeroed "Total By Header" row, not an error.
        $this->get('/api/v1/debit-notes/export?mode=summary&format=xlsx')->assertOk();

        Excel::assertDownloaded('DebitNoteToCustomerListing_Summary_'.now()->toDateString().'_'.now()->toDateString().'.xlsx', function ($export) {
            $rows = $export->array();
            $totalRow = collect($rows)->first(fn ($row) => ($row[3] ?? null) === 'Total By Header');
            $this->assertNotNull($totalRow);
            $this->assertSame(0.0, $totalRow[4]);
            $this->assertSame(0.0, $totalRow[7]);

            return true;
        });
    }

    public function test_debit_note_summary_export_has_correct_headings(): void
    {
        Excel::fake();

        DebitNote::query()->create([
            'invoice_id' => $this->invoice->id, 'customer_id' => $this->customer->id,
            'debit_note_date' => '2026-08-11', 'reason' => 'price_correction',
            'subtotal_goods' => 40000, 'subtotal_other' => 0, 'tax_amount' => 4400, 'total_amount' => 44400,
        ]);

        $this->get('/api/v1/debit-notes/export?mode=summary&format=xlsx')->assertOk();

        Excel::assertDownloaded('DebitNoteToCustomerListing_Summary_'.now()->toDateString().'_'.now()->toDateString().'.xlsx', function ($export) {
            $rows = $export->array();
            $this->assertSame(['Date', 'Document', 'Customer', 'Customer Name', 'Excl.Tax', 'Disc', 'Tax', 'Incl.Tax'], $rows[7]);

            return true;
        });
    }
}
