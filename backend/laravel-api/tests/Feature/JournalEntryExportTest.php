<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
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

/** General Journal's Branch filter (resolved via the origin transaction, not journal_entry_lines.branch_id) and its Export endpoint. */
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

    protected function invoiceJournalEntry(Branch $branch, Customer $customer): JournalEntry
    {
        $salesOrder = SalesOrder::query()->create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
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

    protected function makeBranch(Company $company, string $name, string $code): Branch
    {
        return Branch::query()->create(['company_id' => $company->id, 'name' => $name, 'code' => $code]);
    }

    public function test_branch_filter_narrows_journal_entries_to_the_right_branch(): void
    {
        $this->actingUserWithJournalEntryView();

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branchA = $this->makeBranch($company, 'Branch A', 'A');
        $branchB = $this->makeBranch($company, 'Branch B', 'B');
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $entryA = $this->invoiceJournalEntry($branchA, $customer);
        $this->invoiceJournalEntry($branchB, $customer);

        $response = $this->getJson("/api/v1/journal-entries?branch_id={$branchA->id}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$entryA->id], $ids);
    }

    public function test_export_downloads_a_file_respecting_the_branch_filter(): void
    {
        $this->actingUserWithJournalEntryView();
        Excel::fake();

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branchA = $this->makeBranch($company, 'Branch A', 'A');
        $branchB = $this->makeBranch($company, 'Branch B', 'B');
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $this->invoiceJournalEntry($branchA, $customer);
        $this->invoiceJournalEntry($branchB, $customer);

        $this->get("/api/v1/journal-entries/export?format=xlsx&branch_id={$branchA->id}")->assertOk();

        Excel::assertDownloaded('general-journal.xlsx', function ($export) {
            return $export->collection()->count() === 1;
        });
    }
}
