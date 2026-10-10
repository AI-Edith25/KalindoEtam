<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * General Journal is manual-entry only: JournalEntryRepository::filteredQuery() hard-filters
 * to reference_type IS NULL, so a system-generated entry (e.g. posted for an Invoice) must
 * never appear in its index or export, regardless of any other filter.
 */
class JournalEntryExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
    }

    protected function actingUserWithJournalEntryView(): void
    {
        Permission::query()->firstOrCreate(['name' => 'accounting.journal_entries.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.journal_entries.view');
        Sanctum::actingAs($user);
    }

    protected function manualJournalEntry(): JournalEntry
    {
        return JournalEntry::query()->create([
            'status' => DocumentStatus::SUBMITTED,
            'posting_date' => now()->toDateString(),
            'reference_type' => null,
            'reference_id' => null,
            'description' => 'Manual journal entry',
            'total_debit' => 50000,
            'total_credit' => 50000,
        ]);
    }

    protected function invoiceJournalEntry(): JournalEntry
    {
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $salesOrder = SalesOrder::query()->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods',
            'status' => 'submitted',
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 100000,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => 100000,
        ]);
        $invoice->salesOrders()->attach($salesOrder->id);

        return JournalEntry::query()->create([
            'status' => DocumentStatus::SUBMITTED,
            'posting_date' => now()->toDateString(),
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'total_debit' => 100000,
            'total_credit' => 100000,
        ]);
    }

    public function test_index_only_returns_manual_entries(): void
    {
        $this->actingUserWithJournalEntryView();

        $manual = $this->manualJournalEntry();
        $this->invoiceJournalEntry();

        $response = $this->getJson('/api/v1/journal-entries');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$manual->id], $ids);
    }

    public function test_export_only_includes_manual_entries(): void
    {
        $this->actingUserWithJournalEntryView();
        Excel::fake();

        $this->manualJournalEntry();
        $this->invoiceJournalEntry();

        $this->get('/api/v1/journal-entries/export?format=xlsx')->assertOk();

        Excel::assertDownloaded('general-journal.xlsx', function ($export) {
            return $export->collection()->count() === 1;
        });
    }

    public function test_fetching_by_ids_includes_non_manual_entries(): void
    {
        $this->actingUserWithJournalEntryView();

        $invoiceEntry = $this->invoiceJournalEntry();

        $response = $this->getJson("/api/v1/journal-entries?ids[]={$invoiceEntry->id}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$invoiceEntry->id], $ids);
    }
}
