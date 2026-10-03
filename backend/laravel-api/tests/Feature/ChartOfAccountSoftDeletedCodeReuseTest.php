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

}
