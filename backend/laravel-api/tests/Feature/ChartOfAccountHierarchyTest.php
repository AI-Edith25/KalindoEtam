<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two-level parent/child COA hierarchy (2026-10-09, see project memory
 * project_erp_coa_subaccount_backlog). Covers both the generic parent_id feature and the
 * specific Piutang restructure (2026_10_09_000002_restructure_piutang_accounts_into_hierarchy)
 * that uses it.
 */
class ChartOfAccountHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.chart_of_accounts.create', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'master.chart_of_accounts.update', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['master.chart_of_accounts.create', 'master.chart_of_accounts.update']);
        Sanctum::actingAs($user);
    }

    public function test_a_child_account_can_be_created_under_a_parent(): void
    {
        $parent = ChartOfAccount::query()->create(['code' => '900', 'name' => 'GROUP', 'account_type' => 'asset']);

        $response = $this->postJson('/api/v1/chart-of-accounts', [
            'code' => '900.01',
            'name' => 'Child',
            'account_type' => 'asset',
            'parent_id' => $parent->id,
        ]);

        $response->assertCreated();
        $this->assertSame($parent->id, $response->json('data.parent_id'));
        $this->assertSame('900', $response->json('data.parent.code'));
    }

    public function test_three_level_nesting_is_rejected_choosing_a_child_as_parent(): void
    {
        $grandparent = ChartOfAccount::query()->create(['code' => '900', 'name' => 'GROUP', 'account_type' => 'asset']);
        $parent = ChartOfAccount::query()->create(['code' => '900.01', 'name' => 'Child', 'account_type' => 'asset', 'parent_id' => $grandparent->id]);

        $response = $this->postJson('/api/v1/chart-of-accounts', [
            'code' => '900.01.01',
            'name' => 'Grandchild',
            'account_type' => 'asset',
            'parent_id' => $parent->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('parent_id');
    }

    public function test_an_account_with_children_cannot_itself_become_a_child(): void
    {
        $parent = ChartOfAccount::query()->create(['code' => '900', 'name' => 'GROUP', 'account_type' => 'asset']);
        ChartOfAccount::query()->create(['code' => '900.01', 'name' => 'Child', 'account_type' => 'asset', 'parent_id' => $parent->id]);
        $otherParent = ChartOfAccount::query()->create(['code' => '950', 'name' => 'OTHER GROUP', 'account_type' => 'asset']);

        $response = $this->putJson("/api/v1/chart-of-accounts/{$parent->id}", ['parent_id' => $otherParent->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('parent_id');
    }

    public function test_an_account_cannot_be_its_own_parent(): void
    {
        $account = ChartOfAccount::query()->create(['code' => '900', 'name' => 'GROUP', 'account_type' => 'asset']);

        $response = $this->putJson("/api/v1/chart-of-accounts/{$account->id}", ['parent_id' => $account->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('parent_id');
    }

    public function test_seeded_piutang_hierarchy_has_one_parent_and_four_children(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $parent = ChartOfAccount::query()->where('code', '112')->sole();
        $this->assertNull($parent->parent_id);

        $children = ChartOfAccount::query()->where('parent_id', $parent->id)->pluck('code')->sort()->values();
        $this->assertSame(['112.01', '112.02', '112.03', '112.04'], $children->all());
    }

    public function test_migration_renames_legacy_1200_in_place_preserving_id_and_hard_deletes_piutang_direksi(): void
    {
        $ar = ChartOfAccount::query()->create(['code' => '1200', 'name' => 'PIUTANG USAHA', 'account_type' => 'asset']);
        $karyawan = ChartOfAccount::query()->create(['code' => '1260', 'name' => 'PIUTANG KARYAWAN', 'account_type' => 'asset']);
        $lainLain = ChartOfAccount::query()->create(['code' => '1225', 'name' => 'PIUTANG LAIN-LAIN', 'account_type' => 'asset']);
        $cadangan = ChartOfAccount::query()->create(['code' => '1201', 'name' => 'CADANGAN PIUTANG', 'account_type' => 'asset']);
        $direksi = ChartOfAccount::query()->create(['code' => '1215', 'name' => 'PIUTANG DIREKSI', 'account_type' => 'asset']);
        $direksiTwin = ChartOfAccount::query()->create(['code' => '112.02.01', 'name' => 'PIUTANG DIREKSI', 'account_type' => 'asset']);

        $arId = $ar->id;

        (require database_path('migrations/2026_10_09_000002_restructure_piutang_accounts_into_hierarchy.php'))->up();

        $this->assertSame('112.01', $ar->fresh()->code);
        $this->assertSame($arId, $ar->fresh()->id); // same row — history/balance preserved, not a new account
        $this->assertSame('112.02', $karyawan->fresh()->code);
        $this->assertSame('112.03', $lainLain->fresh()->code);
        $this->assertSame('112.04', $cadangan->fresh()->code);

        $parent = ChartOfAccount::query()->where('code', '112')->sole();
        $this->assertSame($parent->id, $ar->fresh()->parent_id);
        $this->assertSame($parent->id, $karyawan->fresh()->parent_id);

        $this->assertSame(0, ChartOfAccount::query()->whereKey($direksi->id)->count());
        $this->assertSame(0, ChartOfAccount::query()->withTrashed()->whereKey($direksi->id)->count()); // hard-deleted, not soft
        $this->assertSame(0, ChartOfAccount::query()->withTrashed()->whereKey($direksiTwin->id)->count());
    }

    public function test_followup_migration_hard_deletes_the_remaining_legacy_dotted_twins(): void
    {
        // 112.01 already exists (seeded by 2026_10_09_000002, which ran during RefreshDatabase
        // setup) — these three are the untouched legacy-dotted duplicate twins that migration
        // deliberately left alone, a dot deeper than their sibling (112.01.02 reads as a child of
        // 112.01), which is exactly the bug this follow-up migration fixes.
        $cadanganTwin = ChartOfAccount::query()->create(['code' => '112.01.02', 'name' => 'CADANGAN PIUTANG', 'account_type' => 'asset']);
        $karyawanTwin = ChartOfAccount::query()->create(['code' => '112.03.01', 'name' => 'PIUTANG KARYAWAN', 'account_type' => 'asset']);
        $lainLainTwin = ChartOfAccount::query()->create(['code' => '112.09.01', 'name' => 'PIUTANG LAIN-LAIN', 'account_type' => 'asset']);

        (require database_path('migrations/2026_10_09_000003_delete_untouched_piutang_legacy_duplicate_twins.php'))->up();

        foreach ([$cadanganTwin, $karyawanTwin, $lainLainTwin] as $twin) {
            $this->assertSame(0, ChartOfAccount::query()->withTrashed()->whereKey($twin->id)->count(), "{$twin->code} must be hard-deleted, not soft-deleted.");
        }
    }

    public function test_seeded_hutang_hierarchy_has_one_parent_and_four_children(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $parent = ChartOfAccount::query()->where('code', '210')->sole();
        $this->assertNull($parent->parent_id);

        $children = ChartOfAccount::query()->where('parent_id', $parent->id)->pluck('code')->sort()->values();
        $this->assertSame(['210.01', '210.02', '210.03', '210.04'], $children->all());
    }

    public function test_seeded_hutang_pajak_hierarchy_has_one_parent_and_five_children(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $parent = ChartOfAccount::query()->where('code', '213')->sole();
        $this->assertNull($parent->parent_id);

        $children = ChartOfAccount::query()->where('parent_id', $parent->id)->pluck('code')->sort()->values();
        $this->assertSame(['213.01', '213.02', '213.03', '213.04', '213.05'], $children->all());
    }

    public function test_migration_renames_legacy_2000_and_2100_in_place_and_hard_deletes_hutang_direksi(): void
    {
        $ap = ChartOfAccount::query()->create(['code' => '2000', 'name' => 'Utang Usaha', 'account_type' => 'liability']);
        $leasing = ChartOfAccount::query()->create(['code' => '2300', 'name' => 'HUTANG LEASING', 'account_type' => 'liability']);
        $lainLain = ChartOfAccount::query()->create(['code' => '2500', 'name' => 'HUTANG LAIN-LAIN', 'account_type' => 'liability']);
        $bbm = ChartOfAccount::query()->create(['code' => '2600', 'name' => 'HUTANG BBM', 'account_type' => 'liability']);
        $direksi = ChartOfAccount::query()->create(['code' => '2400', 'name' => 'HUTANG KPD DIREKSI', 'account_type' => 'liability']);
        $direksiTwin = ChartOfAccount::query()->create(['code' => '210.02.01', 'name' => 'HUTANG KPD DIREKSI', 'account_type' => 'liability']);
        $leasingTwin = ChartOfAccount::query()->create(['code' => '210.03.01', 'name' => 'HUTANG LEASING', 'account_type' => 'liability']);

        $tax = ChartOfAccount::query()->create(['code' => '2100', 'name' => 'Tax Payable', 'account_type' => 'liability']);
        $ppn = ChartOfAccount::query()->create(['code' => '2101', 'name' => 'HUTANG PPN', 'account_type' => 'liability']);
        $ppnTwin = ChartOfAccount::query()->create(['code' => '219.01.01', 'name' => 'HUTANG PPN', 'account_type' => 'liability']);

        $apId = $ap->id;
        $taxId = $tax->id;

        (require database_path('migrations/2026_10_09_000004_restructure_hutang_accounts_into_hierarchy.php'))->up();
        (require database_path('migrations/2026_10_09_000005_restructure_hutang_pajak_accounts_into_hierarchy.php'))->up();

        $this->assertSame('210.01', $ap->fresh()->code);
        $this->assertSame($apId, $ap->fresh()->id); // same row — history/balance preserved
        $this->assertSame('210.02', $leasing->fresh()->code);
        $this->assertSame('210.03', $lainLain->fresh()->code);
        $this->assertSame('210.04', $bbm->fresh()->code);

        $this->assertSame('213.01', $tax->fresh()->code);
        $this->assertSame($taxId, $tax->fresh()->id);
        $this->assertSame('213.02', $ppn->fresh()->code);

        $hutangParent = ChartOfAccount::query()->where('code', '210')->sole();
        $this->assertSame($hutangParent->id, $ap->fresh()->parent_id);

        $pajakParent = ChartOfAccount::query()->where('code', '213')->sole();
        $this->assertSame($pajakParent->id, $tax->fresh()->parent_id);

        foreach ([$direksi, $direksiTwin, $leasingTwin, $ppnTwin] as $deleted) {
            $this->assertSame(0, ChartOfAccount::query()->withTrashed()->whereKey($deleted->id)->count(), "{$deleted->code} must be hard-deleted.");
        }
    }
}
