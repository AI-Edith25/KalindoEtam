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
 * IncomeStatementImportController::store()'s own logic — the duplicate-period confirmation gate
 * that happens before an ImportBatch is even created. Parsing/matching/posting itself is
 * IncomeStatementImportService's own concern, covered in IncomeStatementImportServiceTest.
 */
class IncomeStatementImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        ChartOfAccount::query()->create(['code' => '410.01.02', 'name' => 'PENJUALAN KREDIT', 'account_type' => 'revenue', 'is_active' => true]);

        Permission::query()->firstOrCreate(['name' => 'accounting.profit_loss.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.profit_loss.import');
        Sanctum::actingAs($user);
    }

    private const PREAMBLE = "INCOME STATEMENT\r\nPT. KALINDO ETAM\r\n01/01/2022 - 31/12/2025,,,17/09/2026 10:09:44\r\n,,,\r\n,,Year-To-Date (RP),%\r\n";

    private function csv(): string
    {
        return self::PREAMBLE
            .'Income,,,'."\r\n"
            .'410.01.02,PENJUALAN KREDIT,100000,'."\r\n"
            .'Total Income,,100000,'."\r\n";
    }

    public function test_missing_account_data_is_rejected_outright(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent('is.csv', "INCOME STATEMENT\r\nPT. KALINDO ETAM\r\n01/01/2022 - 31/12/2025\r\n,,,\r\n,,Year-To-Date (RP),%\r\nFoo,Bar,,\r\n");

        $response = $this->postJson('/api/v1/profit-loss/import', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertSame(0, ImportBatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_duplicate_period_requires_confirmation_before_queuing_again(): void
    {
        $first = UploadedFile::fake()->createWithContent('is.csv', $this->csv());
        $this->postJson('/api/v1/profit-loss/import', ['file' => $first])->assertStatus(201);

        Queue::fake();
        $second = UploadedFile::fake()->createWithContent('is.csv', $this->csv());
        $response = $this->postJson('/api/v1/profit-loss/import', ['file' => $second]);

        $response->assertStatus(409);
        $response->assertJsonPath('data.requires_confirmation', true);
        $response->assertJsonPath('data.reason', 'duplicate');
        Queue::assertNothingPushed();
    }

    public function test_duplicate_policy_create_anyway_proceeds_past_the_gate(): void
    {
        $first = UploadedFile::fake()->createWithContent('is.csv', $this->csv());
        $this->postJson('/api/v1/profit-loss/import', ['file' => $first])->assertStatus(201);

        $second = UploadedFile::fake()->createWithContent('is.csv', $this->csv());
        $response = $this->postJson('/api/v1/profit-loss/import', ['file' => $second, 'duplicate_policy' => 'create_anyway']);

        $response->assertStatus(201);
        $this->assertSame(2, JournalEntry::query()->where('source_document_number', 'IMPORT-IS-01/01/2022-31/12/2025')->count());
    }
}
