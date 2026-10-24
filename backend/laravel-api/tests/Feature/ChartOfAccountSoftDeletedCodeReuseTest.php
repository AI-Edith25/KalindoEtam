<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A chart_of_accounts `code` is only unique among non-trashed rows for CREATE — the Chart of
 * Accounts list/search UI already excludes soft-deleted rows via Eloquent's global scope, so a
 * code that was archived (e.g. the 2026-09-27 unused-cash/bank cleanup) must be re-creatable, not
 * permanently "taken" by a row nothing on screen shows. See StoreChartOfAccountRequest's own
 * whereNull('deleted_at') comment and ChartOfAccountService::create()'s restore-instead-of-insert
 * handling (the raw DB column still has a plain, not soft-delete-aware, unique index).
 */
class ChartOfAccountSoftDeletedCodeReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.chart_of_accounts.create', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.chart_of_accounts.create');
        Sanctum::actingAs($user);
    }

    public function test_create_accepts_a_code_whose_only_match_is_soft_deleted(): void
    {
        $trashed = ChartOfAccount::query()->create(['code' => '1002', 'name' => 'KAS BALIKPAPAN (old)', 'account_type' => 'asset']);
        $trashed->delete();

        $response = $this->postJson('/api/v1/chart-of-accounts', [
            'code' => '1002',
            'name' => 'KAS BALIKPAPAN',
            'account_type' => 'asset',
        ]);

        $response->assertCreated();
        $this->assertSame('1002', $response->json('data.code'));
    }

    public function test_create_still_rejects_a_code_used_by_a_live_row(): void
    {
        ChartOfAccount::query()->create(['code' => '1002', 'name' => 'KAS BALIKPAPAN', 'account_type' => 'asset']);

        $response = $this->postJson('/api/v1/chart-of-accounts', [
            'code' => '1002',
            'name' => 'Duplicate',
            'account_type' => 'asset',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
    }

    /**
     * Same gap as create(), but for update() — a different live account being renamed onto
     * an archived code (the exact bug reported: editing a code to "120.01" was rejected as
     * "already taken" by a trashed row nothing on screen showed).
     */
    public function test_update_accepts_renaming_onto_a_code_whose_only_match_is_soft_deleted(): void
    {
        Permission::query()->firstOrCreate(['name' => 'master.chart_of_accounts.update', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.chart_of_accounts.update');
        Sanctum::actingAs($user);

        $trashed = ChartOfAccount::query()->create(['code' => '120.01', 'name' => 'HUTANG BANK BCA (old)', 'account_type' => 'liability']);
        $trashed->delete();
        $live = ChartOfAccount::query()->create(['code' => '999.99', 'name' => 'Some Other Account', 'account_type' => 'liability']);

        $response = $this->putJson("/api/v1/chart-of-accounts/{$live->id}", ['code' => '120.01']);

        $response->assertOk();
        $this->assertSame('120.01', $response->json('data.code'));
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $trashed->id]);
    }
}
