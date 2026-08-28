<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\SalesPerson;
use App\Models\SalesTarget;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Master Sales Target CRUD + the "one target per sales person per period(+branch)" business rule. */
class SalesTargetCrudTest extends TestCase
{
    use RefreshDatabase;

    protected SalesPerson $salesPerson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'master.sales_targets.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'master.sales_targets.create', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'master.sales_targets.update', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'master.sales_targets.delete', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['master.sales_targets.view', 'master.sales_targets.create', 'master.sales_targets.update', 'master.sales_targets.delete']);
        Sanctum::actingAs($user);

        $this->salesPerson = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi Santoso']);
    }

    public function test_create_sales_target(): void
    {
        $response = $this->postJson('/api/v1/sales-targets', [
            'sales_person_id' => $this->salesPerson->id,
            'period_month' => 8,
            'period_year' => 2026,
            'target_amount' => 10000000,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('sales_targets', [
            'sales_person_id' => $this->salesPerson->id,
            'period_month' => 8,
            'period_year' => 2026,
            'branch_id' => null,
        ]);
    }

    public function test_negative_target_amount_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/sales-targets', [
            'sales_person_id' => $this->salesPerson->id,
            'period_month' => 8,
            'period_year' => 2026,
            'target_amount' => -1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('target_amount');
    }

    public function test_duplicate_target_for_same_period_and_no_branch_is_rejected(): void
    {
        SalesTarget::query()->create([
            'sales_person_id' => $this->salesPerson->id,
            'period_month' => 8,
            'period_year' => 2026,
            'target_amount' => 10000000,
        ]);

        $response = $this->postJson('/api/v1/sales-targets', [
            'sales_person_id' => $this->salesPerson->id,
            'period_month' => 8,
            'period_year' => 2026,
            'target_amount' => 5000000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sales_person_id');
    }

    public function test_same_sales_person_and_period_but_different_branch_is_allowed(): void
    {
        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branchA = Branch::query()->create(['company_id' => $company->id, 'name' => 'Samarinda', 'code' => 'SMD']);
        $branchB = Branch::query()->create(['company_id' => $company->id, 'name' => 'Balikpapan', 'code' => 'BPP']);

        SalesTarget::query()->create([
            'sales_person_id' => $this->salesPerson->id,
            'branch_id' => $branchA->id,
            'period_month' => 8,
            'period_year' => 2026,
            'target_amount' => 10000000,
        ]);

        $response = $this->postJson('/api/v1/sales-targets', [
            'sales_person_id' => $this->salesPerson->id,
            'branch_id' => $branchB->id,
            'period_month' => 8,
            'period_year' => 2026,
            'target_amount' => 5000000,
        ]);

        $response->assertCreated();
    }

    public function test_update_does_not_collide_with_its_own_existing_row(): void
    {
        $target = SalesTarget::query()->create([
            'sales_person_id' => $this->salesPerson->id,
            'period_month' => 8,
            'period_year' => 2026,
            'target_amount' => 10000000,
        ]);

        $response = $this->putJson("/api/v1/sales-targets/{$target->id}", [
            'target_amount' => 12000000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('sales_targets', ['id' => $target->id, 'target_amount' => 12000000]);
    }

    public function test_delete_sales_target(): void
    {
        $target = SalesTarget::query()->create([
            'sales_person_id' => $this->salesPerson->id,
            'period_month' => 8,
            'period_year' => 2026,
            'target_amount' => 10000000,
        ]);

        $this->deleteJson("/api/v1/sales-targets/{$target->id}")->assertOk();
        $this->assertSoftDeleted('sales_targets', ['id' => $target->id]);
    }
}
