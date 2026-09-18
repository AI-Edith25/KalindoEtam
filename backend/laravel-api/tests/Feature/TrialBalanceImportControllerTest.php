<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TrialBalanceImportController::store()'s own logic — the two confirmation gates that happen
 * before an ImportBatch is even created (duplicate period, out-of-balance file). Parsing/matching/
 * posting itself is TrialBalanceImportService's own concern, covered in TrialBalanceImportServiceTest.
 */
class TrialBalanceImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        ChartOfAccount::query()->create(['code' => '1100', 'name' => 'Kas Besar', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '3000', 'name' => 'Modal', 'account_type' => 'equity', 'is_active' => true]);

        Permission::query()->firstOrCreate(['name' => 'accounting.trial_balance.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.trial_balance.import');
        Sanctum::actingAs($user);
    }

    private const PREAMBLE = "TRIAL BALANCE\r\nPT. KALINDO ETAM\r\n01/01/2022 - 31/12/2025,,,,17/09/2026 10:09:44\r\n\r\n";

    private const HEADER = "ACCOUNT # ,DESCRIPTION,YEAR TO DATE [DR] (RP),YEAR TO DATE [CR] (RP)\r\n";

    private function balancedCsv(): string
    {
        return self::PREAMBLE.self::HEADER
            .'1100,Kas Besar,500000,0'."\r\n"
            .'350.01.01,MODAL,0,500000'."\r\n"
            .',,500000,500000'."\r\n";
    }

    private function outOfBalanceCsv(): string
    {
        return self::PREAMBLE.self::HEADER
            .'1100,Kas Besar,600000,0'."\r\n"
            .'350.01.01,MODAL,0,500000'."\r\n"
            .',,600000,500000'."\r\n"
            .',OUT OF BALANCE BY,100000,'."\r\n";
    }

    public function test_missing_required_columns_are_rejected_outright(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent('tb.csv', "TRIAL BALANCE\r\nPT. KALINDO ETAM\r\n01/01/2022 - 31/12/2025\r\n\r\nFoo,Bar\r\n1,2\r\n");

        $response = $this->postJson('/api/v1/trial-balance/import', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertSame(0, ImportBatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_out_of_balance_file_requires_confirmation_before_queuing(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent('tb.csv', $this->outOfBalanceCsv());

        $response = $this->postJson('/api/v1/trial-balance/import', ['file' => $file]);

        $response->assertStatus(409);
        $response->assertJsonPath('data.requires_confirmation', true);
        $response->assertJsonPath('data.reason', 'out_of_balance');
        $this->assertStringContainsString('Rp 100.000', $response->json('message'));
        $this->assertSame(0, ImportBatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_confirm_out_of_balance_proceeds_past_the_gate(): void
    {
        $file = UploadedFile::fake()->createWithContent('tb.csv', $this->outOfBalanceCsv());

        // Synchronous queue (see .env.testing/phpunit.xml) — the job runs inline.
        $response = $this->postJson('/api/v1/trial-balance/import', ['file' => $file, 'confirm_out_of_balance' => true]);

        $response->assertStatus(201);
        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->count());
    }

    public function test_duplicate_period_requires_confirmation_before_queuing_again(): void
    {
        $first = UploadedFile::fake()->createWithContent('tb.csv', $this->balancedCsv());
        $this->postJson('/api/v1/trial-balance/import', ['file' => $first])->assertStatus(201);

        Queue::fake();
        $second = UploadedFile::fake()->createWithContent('tb.csv', $this->balancedCsv());
        $response = $this->postJson('/api/v1/trial-balance/import', ['file' => $second]);

        $response->assertStatus(409);
        $response->assertJsonPath('data.reason', 'duplicate');
        Queue::assertNothingPushed();
    }

    public function test_duplicate_policy_create_anyway_proceeds_past_the_gate(): void
    {
        $first = UploadedFile::fake()->createWithContent('tb.csv', $this->balancedCsv());
        $this->postJson('/api/v1/trial-balance/import', ['file' => $first])->assertStatus(201);

        $second = UploadedFile::fake()->createWithContent('tb.csv', $this->balancedCsv());
        $response = $this->postJson('/api/v1/trial-balance/import', ['file' => $second, 'duplicate_policy' => 'create_anyway']);

        $response->assertStatus(201);
        $this->assertSame(2, JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->count());
    }
}
