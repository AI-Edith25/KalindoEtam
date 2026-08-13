<?php

namespace Tests\Feature;

use App\Enums\CashBankCategory;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Services\ReceiptEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** E1 (UAT review 2026-08-12) — Journal List's XLSX export, same filters/grouping as the on-screen report. */
class JournalListExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_downloads_an_xlsx_matching_the_grouped_report_shape(): void
    {
        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $bankAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $bankAccount->update(['cash_bank_category' => CashBankCategory::CASH_BOOK]);

        $receipt = app(ReceiptEntryService::class)->create([
            'customer_id' => $customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $bankAccount->id,
            'total_amount' => 50000,
        ]);
        app(ReceiptEntryService::class)->submit($receipt);

        Permission::query()->firstOrCreate(['name' => 'accounting.journal_list.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.journal_list.view');
        Sanctum::actingAs($user);

        Excel::fake();

        $this->get('/api/v1/journal-list/export')->assertOk();

        Excel::assertDownloaded('journal-list.xlsx', function ($export) {
            $rows = $export->array();
            // Group header + 1 data row + subtotal row + grand total row.
            return count($rows) === 4 && $rows[0][0] === 'Cash Book-Receipt' && $rows[3][0] === 'Grand Total';
        });
    }
}
