<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithDeletePermission(): User
    {
        Permission::query()->firstOrCreate(['name' => 'administration.users.delete', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->givePermissionTo('administration.users.delete');

        return $admin;
    }

    public function test_admin_can_soft_delete_another_user(): void
    {
        $admin = $this->adminWithDeletePermission();
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/v1/users/{$target->id}");

        $response->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deleted', 'module' => 'user']);
    }

    public function test_deleting_a_user_revokes_their_tokens(): void
    {
        $admin = $this->adminWithDeletePermission();
        $target = User::factory()->create();
        $target->createToken('frontend');
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$target->id}")->assertOk();

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->adminWithDeletePermission();
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/v1/users/{$admin->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_user_without_permission_cannot_delete(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/users/{$target->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }
}
