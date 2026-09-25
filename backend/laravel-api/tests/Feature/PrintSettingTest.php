<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPrintSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrintSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_no_saved_row_gets_null_for_every_document_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/print-settings');

        $response->assertOk();
        $response->assertJson(['data' => ['delivery-order' => null, 'invoice' => null]]);
    }

    public function test_a_user_can_save_and_read_back_their_own_settings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/print-settings/invoice', ['paperType' => 'dotmatrix_half', 'dotMatrixHeightMm' => 130])
            ->assertOk();

        $response = $this->getJson('/api/v1/print-settings');
        $response->assertOk();
        $response->assertJsonPath('data.invoice.paperType', 'dotmatrix_half');
        $response->assertJsonPath('data.invoice.dotMatrixHeightMm', 130);
        $response->assertJsonPath('data.delivery-order', null);

        $this->assertSame(1, UserPrintSetting::query()->where('user_id', $user->id)->count());
    }

    public function test_saving_the_same_document_type_twice_upserts_instead_of_duplicating(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/print-settings/invoice', ['paperType' => 'half'])->assertOk();
        $this->putJson('/api/v1/print-settings/invoice', ['paperType' => 'dotmatrix_half'])->assertOk();

        $this->assertSame(1, UserPrintSetting::query()->where('user_id', $user->id)->where('document_type', 'invoice')->count());
        $this->getJson('/api/v1/print-settings')->assertJsonPath('data.invoice.paperType', 'dotmatrix_half');
    }

    public function test_an_unsupported_document_type_404s(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/print-settings/sales-order', ['paperType' => 'a4'])->assertNotFound();
    }

    public function test_one_users_settings_are_invisible_to_another_user(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->putJson('/api/v1/print-settings/invoice', ['paperType' => 'dotmatrix_half'])->assertOk();

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/print-settings')->assertJsonPath('data.invoice', null);
    }

    public function test_admin_can_view_and_update_another_users_settings(): void
    {
        Permission::query()->firstOrCreate(['name' => 'administration.print_settings.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'administration.print_settings.update', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->givePermissionTo(['administration.print_settings.view', 'administration.print_settings.update']);
        $target = User::factory()->create();

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/users/{$target->id}/print-settings")
            ->assertOk()
            ->assertJson(['data' => ['delivery-order' => null, 'invoice' => null]]);

        $this->putJson("/api/v1/users/{$target->id}/print-settings/delivery-order", [
            'paperType' => 'dotmatrix_half',
            'dotMatrixHeightMm' => 132,
            'dotMatrixOffsetLeftMm' => 2,
        ])->assertOk();

        $this->getJson("/api/v1/users/{$target->id}/print-settings")
            ->assertJsonPath('data.delivery-order.dotMatrixHeightMm', 132);

        $setting = UserPrintSetting::query()->where('user_id', $target->id)->where('document_type', 'delivery-order')->firstOrFail();
        $this->assertSame($admin->id, $setting->updated_by);
    }

    public function test_a_user_without_the_admin_permission_is_forbidden_from_another_users_settings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $target = User::factory()->create();

        $this->getJson("/api/v1/users/{$target->id}/print-settings")->assertForbidden();
        $this->putJson("/api/v1/users/{$target->id}/print-settings/invoice", ['paperType' => 'a4'])->assertForbidden();
    }
}
