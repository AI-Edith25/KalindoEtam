<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Permission;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Main warehouse" = Warehouse::warehouse_type === MAIN (no separate is_main column). Per-Warehouse
 * Pricing's "Sync to Main WH" and Sales Order's pricing resolution both need exactly one
 * unambiguous Main warehouse — see WarehouseService::assertSingleMainWarehouse().
 */
class WarehouseMainUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            Permission::query()->firstOrCreate(['name' => "master.warehouses.{$action}", 'guard_name' => 'web']);
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['master.warehouses.view', 'master.warehouses.create', 'master.warehouses.update']);
        Sanctum::actingAs($user);
    }

    public function test_creating_a_second_main_warehouse_is_rejected(): void
    {
        Warehouse::query()->create(['name' => 'Balikpapan', 'code' => 'BPP', 'warehouse_type' => WarehouseType::MAIN]);

        $response = $this->postJson('/api/v1/warehouses', ['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => 'main']);

        $response->assertStatus(422);
        $this->assertSame(1, Warehouse::query()->where('warehouse_type', WarehouseType::MAIN)->count());
    }

    public function test_creating_a_non_main_warehouse_is_unaffected_by_an_existing_main(): void
    {
        Warehouse::query()->create(['name' => 'Balikpapan', 'code' => 'BPP', 'warehouse_type' => WarehouseType::MAIN]);

        $response = $this->postJson('/api/v1/warehouses', ['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => 'transit']);

        $response->assertCreated();
    }

    public function test_updating_a_warehouse_to_main_is_rejected_when_another_main_already_exists(): void
    {
        Warehouse::query()->create(['name' => 'Balikpapan', 'code' => 'BPP', 'warehouse_type' => WarehouseType::MAIN]);
        $transit = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::TRANSIT]);

        $response = $this->putJson("/api/v1/warehouses/{$transit->id}", ['warehouse_type' => 'main']);

        $response->assertStatus(422);
    }

    public function test_re_saving_the_existing_main_warehouse_as_main_is_allowed(): void
    {
        $main = Warehouse::query()->create(['name' => 'Balikpapan', 'code' => 'BPP', 'warehouse_type' => WarehouseType::MAIN]);

        $response = $this->putJson("/api/v1/warehouses/{$main->id}", ['name' => 'Balikpapan HQ', 'warehouse_type' => 'main']);

        $response->assertOk();
    }
}
