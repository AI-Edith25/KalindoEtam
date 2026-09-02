<?php

namespace Tests\Feature;

use App\Models\PriceZone;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Master Price Zone CRUD — same shape as Item Groups (name unique, description nullable). */
class PriceZoneCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            Permission::query()->firstOrCreate(['name' => "master.price_zones.{$action}", 'guard_name' => 'web']);
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['master.price_zones.view', 'master.price_zones.create', 'master.price_zones.update', 'master.price_zones.delete']);
        Sanctum::actingAs($user);
    }

    public function test_create_price_zone(): void
    {
        $response = $this->postJson('/api/v1/price-zones', ['name' => 'Samarinda', 'description' => 'Kota Samarinda']);

        $response->assertCreated();
        $this->assertDatabaseHas('price_zones', ['name' => 'Samarinda']);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        PriceZone::query()->create(['name' => 'Samarinda']);

        $response = $this->postJson('/api/v1/price-zones', ['name' => 'Samarinda']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_update_does_not_collide_with_its_own_existing_row(): void
    {
        $zone = PriceZone::query()->create(['name' => 'Samarinda']);

        $response = $this->putJson("/api/v1/price-zones/{$zone->id}", ['name' => 'Samarinda', 'description' => 'Updated']);

        $response->assertOk();
        $this->assertDatabaseHas('price_zones', ['id' => $zone->id, 'description' => 'Updated']);
    }

    public function test_delete_price_zone(): void
    {
        $zone = PriceZone::query()->create(['name' => 'Samarinda']);

        $this->deleteJson("/api/v1/price-zones/{$zone->id}")->assertOk();
        $this->assertSoftDeleted('price_zones', ['id' => $zone->id]);
    }

    public function test_forbidden_without_permission(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->postJson('/api/v1/price-zones', ['name' => 'Balikpapan'])->assertForbidden();
    }
}
