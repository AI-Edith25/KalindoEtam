<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * suppliers_supplier_code_unique used to be global, so a soft-deleted
 * supplier's code stayed permanently reserved — see
 * 2026_09_25_011057_make_supplier_code_unique_per_soft_delete.
 */
class SupplierSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['master.suppliers.view', 'master.suppliers.create', 'master.suppliers.update', 'master.suppliers.delete'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $user = User::factory()->create();
        $user->givePermissionTo(['master.suppliers.view', 'master.suppliers.create', 'master.suppliers.update', 'master.suppliers.delete']);
        Sanctum::actingAs($user);
    }

    public function test_supplier_code_can_be_reused_after_soft_delete(): void
    {
        $supplier = Supplier::query()->create(['supplier_code' => 'SUP-001', 'supplier_name' => 'Old Supplier']);
        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")->assertOk();

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);

        $response = $this->postJson('/api/v1/suppliers', [
            'supplier_code' => 'SUP-001',
            'supplier_name' => 'New Supplier',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('suppliers', ['supplier_code' => 'SUP-001', 'supplier_name' => 'New Supplier', 'deleted_at' => null]);
    }

    public function test_active_duplicate_supplier_code_is_still_rejected(): void
    {
        Supplier::query()->create(['supplier_code' => 'SUP-002', 'supplier_name' => 'Active Supplier']);

        $response = $this->postJson('/api/v1/suppliers', [
            'supplier_code' => 'SUP-002',
            'supplier_name' => 'Duplicate Supplier',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('supplier_code');
    }

    public function test_update_can_keep_a_soft_deleted_supplier_code_but_not_an_active_one(): void
    {
        $deleted = Supplier::query()->create(['supplier_code' => 'SUP-003', 'supplier_name' => 'Deleted Supplier']);
        $deleted->delete();

        $active = Supplier::query()->create(['supplier_code' => 'SUP-004', 'supplier_name' => 'Active Supplier']);

        $this->putJson("/api/v1/suppliers/{$active->id}", [
            'supplier_code' => 'SUP-003',
            'supplier_name' => $active->supplier_name,
        ])->assertOk();

        $another = Supplier::query()->create(['supplier_code' => 'SUP-005', 'supplier_name' => 'Another Supplier']);
        $this->putJson("/api/v1/suppliers/{$another->id}", [
            'supplier_code' => 'SUP-003',
            'supplier_name' => $another->supplier_name,
        ])->assertUnprocessable();
    }

    public function test_deleted_supplier_can_be_listed_and_restored(): void
    {
        $supplier = Supplier::query()->create(['supplier_code' => 'SUP-006', 'supplier_name' => 'Restorable Supplier']);
        $supplier->delete();

        $this->getJson('/api/v1/suppliers/trashed')
            ->assertOk()
            ->assertJsonFragment(['supplier_code' => 'SUP-006']);

        $this->postJson("/api/v1/suppliers/{$supplier->id}/restore")->assertOk();

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'deleted_at' => null]);
    }
}
