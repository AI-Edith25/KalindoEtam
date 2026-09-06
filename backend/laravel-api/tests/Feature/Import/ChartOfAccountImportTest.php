<?php

namespace Tests\Feature\Import;

use App\Models\ChartOfAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 1-step import — Chart of Accounts module, against the real xlscoalisting.xlsx shape:
 * title/company preamble, header on row 4, then "Financial Category : Bxx/Ixx [...]" group
 * headers interleaved with non-postable summary accounts and the real "Detail YN"=YES leaf
 * accounts. Only the leaf accounts should ever be created — everything else is structural
 * noise (see ChartOfAccountImportTemplate's docblock and ImportBatchService::buildCleanedRows()'s
 * `_skip_row` handling).
 */
class ChartOfAccountImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.chart_of_accounts.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.chart_of_accounts.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_auto_import_infers_account_type_from_category_headers_and_skips_non_detail_rows(): void
    {
        $csv = "COA LISTING,,,\n"
            .",,,\n"
            ."PT. KALINDO ETAM,,,06/09/2026 09:58:58\n"
            ."Account Code,Account Description,Currency,Detail YN\n"
            .",,,\n"
            ."1. Financial Category : B10 [Share Capital],,,\n"
            ."350,MODAL SAHAM,Indonesia Rupiah,\n"
            ."350.01.01,MODAL,Indonesia Rupiah,YES\n"
            .",,,\n"
            ."2. Financial Category : B60 [Bank],,,\n"
            ."102,BANK,Indonesia Rupiah,\n"
            ."102.01,BANK SAMARINDA,Indonesia Rupiah,\n"
            ."102.01.01,BANK BCA 1312,Indonesia Rupiah,YES\n"
            .",,,\n"
            ."3. Financial Category : I10 [Income],,,\n"
            ."411.01.01,JASA ANGKUTAN,Indonesia Rupiah,YES\n"
            ."410.01.04,DISKON PENJUALAN,Indonesia Rupiah,YES\n";

        $response = $this->post('/api/v1/import/chart-of-accounts/auto', ['file' => $this->csvFile('coa.csv', $csv)]);

        $response->assertCreated();

        // Only the 4 Detail=YES rows become accounts — the 3 summary rows (350, 102, 102.01)
        // and the 3 category-header rows are excluded entirely, not counted as failures. (A
        // 5th account, code 1250, is the pre-existing "Advance to Suppliers" row every test DB
        // starts with — see migration 2026_08_23_000003_add_advance_to_suppliers_account.)
        $importedCodes = ['350.01.01', '102.01.01', '411.01.01', '410.01.04'];
        $this->assertSame(4, ChartOfAccount::query()->whereIn('code', $importedCodes)->count());

        $modal = ChartOfAccount::query()->where('code', '350.01.01')->firstOrFail();
        $this->assertSame('equity', $modal->account_type->value);
        $this->assertFalse($modal->is_cash_bank);

        $bank = ChartOfAccount::query()->where('code', '102.01.01')->firstOrFail();
        $this->assertSame('asset', $bank->account_type->value);
        $this->assertTrue($bank->is_cash_bank);

        $income = ChartOfAccount::query()->where('code', '411.01.01')->firstOrFail();
        $this->assertSame('revenue', $income->account_type->value);
        $this->assertFalse($income->is_cash_bank);

        $this->assertNull(ChartOfAccount::query()->where('code', '350')->first());
        $this->assertNull(ChartOfAccount::query()->where('code', '102')->first());
    }
}
