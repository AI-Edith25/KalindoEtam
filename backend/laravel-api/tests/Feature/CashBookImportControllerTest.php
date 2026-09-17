<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Permission;
use App\Models\ReceiptEntry;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CashBookImportController::store()'s own logic — the section-label mismatch
 * check that happens before an ImportBatch is even created. Parsing/matching/
 * document-creation itself is CashBookImportService's own concern, covered
 * in CashBookImportServiceTest.
 */
class CashBookImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'accounting.journal_list.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.journal_list.import');
        Sanctum::actingAs($user);
    }

    private const PREAMBLE = "JOURNAL LIST\r\nPT. KALINDO ETAM\r\n01/09/2026 - 30/09/2026,,,,17/09/2026 10:09:44\r\n\r\n";

    private const HEADER = "Transaction,Date,Notes,Particulars,Debit,Credit\r\n";

    private function receiptCsv(string $groupLabel): string
    {
        Customer::query()->firstOrCreate(['customer_code' => 'C0001'], ['customer_name' => 'PT ABC', 'is_active' => true]);

        return self::PREAMBLE.self::HEADER
            ."{$groupLabel},,,,,"."\r\n"
            .'OR-0001,01/09/2026,,"1100 - Cash and Bank - [Unapplied Customer Payments (PT ABC; Cash and Bank)]",500000,0'."\r\n"
            .',01/09/2026,,"1150 - Unapplied Customer Payments - [PT ABC; Cash and Bank]",0,500000'."\r\n"
            ."Total For :[{$groupLabel}],,,,500000,500000\r\n";
    }

    public function test_section_label_mismatch_is_rejected_without_creating_a_batch(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent('receipt.csv', $this->receiptCsv('Cash Book-Receipt'));

        // Uploading a Receipt-shaped file while the UI has "Payment Voucher" selected.
        $response = $this->postJson('/api/v1/cash-book/import', ['file' => $file, 'view' => 'payment']);

        $response->assertStatus(409);
        $response->assertJsonPath('data.requires_confirmation', true);
        $this->assertStringContainsString('Cash Book-Receipt', $response->json('message'));
        $this->assertStringContainsString('Cash Book-Payment', $response->json('message'));
        $this->assertSame(0, ImportBatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_confirm_journal_type_proceeds_past_the_mismatch(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent('receipt.csv', $this->receiptCsv('Cash Book-Receipt'));

        $response = $this->postJson('/api/v1/cash-book/import', ['file' => $file, 'view' => 'payment', 'confirm_journal_type' => true]);

        $response->assertStatus(201);
        $this->assertSame(1, ImportBatch::query()->count());
    }

    public function test_matching_section_label_proceeds_without_any_confirmation(): void
    {
        $file = UploadedFile::fake()->createWithContent('receipt.csv', $this->receiptCsv('Cash Book-Receipt'));

        // Synchronous queue (see .env.testing/phpunit.xml) — the job runs inline, so a genuine
        // ReceiptEntry should exist by the time this request returns.
        $response = $this->postJson('/api/v1/cash-book/import', ['file' => $file, 'view' => 'receipt']);

        $response->assertStatus(201);
        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, ReceiptEntry::query()->where('reference_number', 'OR-0001')->count());
    }
}
