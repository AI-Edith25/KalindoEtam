<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** C2 (UAT review 2026-08-12) — AR Detail's Export endpoint, XLSX and CSV, filter-aware, unpaginated. */
class AccountsReceivableExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
    }

    protected function actingUserWithArDetailView(): void
    {
        Permission::query()->firstOrCreate(['name' => 'reports.ar_detail.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.ar_detail.view');
        Sanctum::actingAs($user);
    }

    public function test_export_downloads_an_xlsx_file_covering_every_filtered_row_past_the_page_cap(): void
    {
        $this->actingUserWithArDetailView();
        Excel::fake();

        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
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
        AccountsReceivable::query()->create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'reference_number' => $invoice->document_number,
            'amount' => 100000,
            'paid_amount' => 0,
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => AccountsReceivableStatus::UNPAID,
        ]);

        $response = $this->get('/api/v1/accounts-receivables/export?format=xlsx');

        $response->assertOk();
        Excel::assertDownloaded('ar-detail.xlsx', function ($export) {
            return $export->collection()->count() === 1;
        });
    }

    public function test_export_respects_the_status_filter(): void
    {
        $this->actingUserWithArDetailView();
        Excel::fake();

        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        foreach ([['status' => AccountsReceivableStatus::UNPAID, 'amount' => 50000], ['status' => AccountsReceivableStatus::PAID, 'amount' => 30000]] as $row) {
            $invoice = Invoice::query()->create([
                'invoice_type' => 'goods',
                'status' => 'submitted',
                'customer_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'subtotal' => $row['amount'],
                'discount_amount' => 0,
                'discount_type' => 'amount',
                'tax_amount' => 0,
                'grand_total' => $row['amount'],
            ]);
            AccountsReceivable::query()->create([
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'reference_number' => $invoice->document_number,
                'amount' => $row['amount'],
                'paid_amount' => $row['status'] === AccountsReceivableStatus::PAID ? $row['amount'] : 0,
                'due_date' => now()->addDays(30)->toDateString(),
                'status' => $row['status'],
            ]);
        }

        $this->get('/api/v1/accounts-receivables/export?format=csv&status=unpaid')->assertOk();

        Excel::assertDownloaded('ar-detail.csv', function ($export) {
            return $export->collection()->count() === 1;
        });
    }
}
