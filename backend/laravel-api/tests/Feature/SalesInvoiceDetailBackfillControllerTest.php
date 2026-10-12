<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level test for the one thing SalesInvoiceDetailBackfillServiceTest can't exercise: the
 * generic ImportController::failedRows() download endpoint, which authorizes via
 * AuthorizesImportModule::authorizeModule() keyed off ImportBatch.module — a string this
 * controller's own routes never declared a permission mapping for. Found 2026-10-12: the "Unduh
 * Baris Dilewati" button rendered correctly (has_failed_rows was true) but clicking it always
 * 403'd, because the trait's default fallback built the permission name
 * 'master.sales_invoice_detail_backfill.import', which no role was ever granted — unlike every
 * sibling import module, which is listed in AuthorizesImportModule::MODULE_PERMISSION_OVERRIDES.
 */
class SalesInvoiceDetailBackfillControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        Storage::fake('local');

        Permission::query()->firstOrCreate(['name' => 'sales.invoices.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.invoices.import']);
        Sanctum::actingAs($user);
    }

    private function fixtureFile(): UploadedFile
    {
        // A document number no Invoice will ever match -> guarantees at least one
        // skipped_not_found row, so the batch's has_failed_rows comes back true.
        $csv = implode("\r\n", [
            'SALES INVOICE LISTING - DETAIL',
            '12/09/2026 - 12/10/2026 - Base Currency',
            '',
            '',
            'PT. KALINDO ETAM',
            '',
            '',
            'DATE,DOCUMENT #,CUSTOMER#,NAME,,DELIVERY TO,,DISC,TAX,T.CODE,AMOUNT,REFERENCE 1 #,REFERENCE 2 #',
            'ITEM #,,DESCRIPTION,,UOM,QUANTITY,UNIT PRICE,DISC,TAX,T.CODE,LINE AMOUNT',
            '12/09/2026,SI/KE/99999/09/2026,C-9999,Nobody,,,,0,0,,500000,,',
            'MISC,,Something,,UNIT,1,500000,0,0,,500000',
            'TAX SUMMARY',
            'Printed By : Admin',
        ])."\r\n";

        $path = tempnam(sys_get_temp_dir(), 'sidb').'.csv';
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'SalesInvoiceListing_Detail.csv', 'text/csv', null, true);
    }

    public function test_the_rejected_rows_csv_can_actually_be_downloaded_after_preflight(): void
    {
        $upload = $this->post('/api/v1/invoices/import-detail', ['file' => $this->fixtureFile()]);
        $upload->assertCreated();
        $this->assertTrue($upload->json('data.has_failed_rows'));

        $batchId = $upload->json('data.id');

        $download = $this->get("/api/v1/import/batches/{$batchId}/failed-rows");

        $download->assertOk();
        $this->assertStringContainsString('SI/KE/99999/09/2026', $download->streamedContent());
    }
}
