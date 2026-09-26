<?php

namespace Tests\Feature;

use App\Enums\BankStatementStatus;
use App\Models\BankStatement;
use App\Models\ChartOfAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankStatementControllerTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'finance.bank_reconciliation.create', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'finance.bank_reconciliation.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['finance.bank_reconciliation.create', 'finance.bank_reconciliation.view']);
        Sanctum::actingAs($user);

        $this->bankAccount = ChartOfAccount::query()->create([
            'code' => '1101', 'name' => 'BANK BCA SMD 1312', 'account_type' => 'asset',
            'is_active' => true, 'is_cash_bank' => true, 'cash_bank_category' => 'cash_book',
        ]);
    }

    private const BCA_CSV = "AccountNo;Ccy;PostDate;Remarks;AdditionalDesc;Credit Amount;Debit Amount;Close Balance\n1234567890123;IDR;01 September 2026 14:24:27;Transfer masuk;Transfer masuk;18429612.00;0.00;130941712.79\n";

    public function test_upload_auto_detects_format_and_returns_preview_without_persisting_lines(): void
    {
        $file = UploadedFile::fake()->createWithContent('statement.csv', self::BCA_CSV);

        $response = $this->postJson('/api/v1/bank-statements', [
            'bank_account_id' => $this->bankAccount->id,
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.batch.format_template', 'bca');
        $this->assertCount(1, $response->json('data.preview_rows'));
        $batch = BankStatement::query()->first();
        $this->assertSame(BankStatementStatus::UPLOADED, $batch->status);
        $this->assertSame(0, $batch->lines()->count());
    }

    public function test_unrecognized_format_without_explicit_template_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('statement.csv', "col1,col2\nfoo,bar\n");

        $response = $this->postJson('/api/v1/bank-statements', [
            'bank_account_id' => $this->bankAccount->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, BankStatement::query()->count());
    }

    public function test_confirm_persists_lines_and_sets_period(): void
    {
        $file = UploadedFile::fake()->createWithContent('statement.csv', self::BCA_CSV);
        $upload = $this->postJson('/api/v1/bank-statements', ['bank_account_id' => $this->bankAccount->id, 'file' => $file]);
        $batchId = $upload->json('data.batch.id');

        $response = $this->postJson("/api/v1/bank-statements/{$batchId}/confirm");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'processed');
        $batch = BankStatement::query()->findOrFail($batchId);
        $this->assertSame(1, $batch->lines()->count());
        $this->assertSame('2026-09-01', $batch->period_start->format('Y-m-d'));
        $this->assertSame('2026-09-01', $batch->period_end->format('Y-m-d'));
    }

    public function test_download_streams_the_originally_uploaded_file(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('statement.csv', self::BCA_CSV);
        $upload = $this->postJson('/api/v1/bank-statements', ['bank_account_id' => $this->bankAccount->id, 'file' => $file]);
        $batchId = $upload->json('data.batch.id');

        $response = $this->get("/api/v1/bank-statements/{$batchId}/download");

        $response->assertStatus(200);
        $response->assertHeader('content-disposition', 'attachment; filename=statement.csv');
    }

    public function test_without_permission_is_forbidden(): void
    {
        $unprivileged = User::factory()->create();
        Sanctum::actingAs($unprivileged);

        $file = UploadedFile::fake()->createWithContent('statement.csv', self::BCA_CSV);
        $response = $this->postJson('/api/v1/bank-statements', ['bank_account_id' => $this->bankAccount->id, 'file' => $file]);

        $response->assertStatus(403);
    }
}
