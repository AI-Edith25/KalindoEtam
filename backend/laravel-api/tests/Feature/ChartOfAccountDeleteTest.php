<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\NamingSeries;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deleting a Chart of Account is a hard delete (forceDelete, not soft delete) — see
 * ChartOfAccountService::delete(). An account that has ever been posted to, even via a
 * since-voided Journal Entry, must be rejected with a clear message rather than bubbling up
 * the raw journal_entry_lines_chart_of_account_id_foreign SQL error production hit on first
 * deploy of 2026_10_24_000001/000002.
 */
class ChartOfAccountDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.chart_of_accounts.delete', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.chart_of_accounts.delete');
        Sanctum::actingAs($user);

        NamingSeries::query()->create(['module' => 'finance', 'document_type' => 'journal', 'prefix' => 'GJ/', 'digit_length' => 5]);
    }

    public function test_an_unused_account_is_hard_deleted_not_soft_deleted(): void
    {
        $account = ChartOfAccount::query()->create(['code' => '9001', 'name' => 'Unused Test Account', 'account_type' => 'asset']);

        $response = $this->deleteJson("/api/v1/chart-of-accounts/{$account->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $account->id]);
    }

    public function test_an_account_with_a_live_journal_entry_line_is_rejected_with_a_clear_message(): void
    {
        $account = ChartOfAccount::query()->create(['code' => '9002', 'name' => 'Used Test Account', 'account_type' => 'asset']);
        $journalEntry = JournalEntry::query()->create(['posting_date' => now()]);
        JournalEntryLine::query()->create([
            'journal_entry_id' => $journalEntry->id,
            'chart_of_account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
        ]);

        $response = $this->deleteJson("/api/v1/chart-of-accounts/{$account->id}");

        $response->assertStatus(422);
        $response->assertJsonFragment(['success' => false]);
        $this->assertStringContainsString('sudah pernah digunakan', $response->json('message'));
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $account->id]);
    }

    /**
     * The exact bug production hit: the only journal_entry_lines row referencing this
     * account belongs to a soft-deleted (voided) Journal Entry Line. A plain
     * `journalEntryLines()->exists()` would miss it (global SoftDeletingScope), but the row
     * still physically exists and still holds the FK — forceDelete() must still be blocked.
     */
    public function test_an_account_whose_only_journal_entry_line_is_soft_deleted_is_still_rejected(): void
    {
        $account = ChartOfAccount::query()->create(['code' => '9003', 'name' => 'Voided-Only Test Account', 'account_type' => 'asset']);
        $journalEntry = JournalEntry::query()->create(['posting_date' => now()]);
        $line = JournalEntryLine::query()->create([
            'journal_entry_id' => $journalEntry->id,
            'chart_of_account_id' => $account->id,
            'debit' => 500,
            'credit' => 0,
        ]);
        $line->delete();

        $response = $this->deleteJson("/api/v1/chart-of-accounts/{$account->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $account->id]);
    }
}
