<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoicePrintSettingTest extends TestCase
{
    use RefreshDatabase;

    /** GET is unguarded (no permission needed) — any authenticated user printing an invoice must be able to load the company default. */
    public function test_any_authenticated_user_can_read_the_default(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/invoice-print-settings');

        $response->assertOk();
        $response->assertJsonPath('data.paper_type', 'a4');
        $response->assertJsonPath('data.visible_columns', ['itemCode', 'description', 'sales', 'qty', 'uom', 'unitCost', 'lineAmt']);
        // Pre-existing hardcoded margins (A4 12mm, Continuous/Half 6mm) — a single flat margin
        // can't represent all three without changing at least one, so this is keyed by paper type.
        $response->assertJsonPath('data.margins.a4.top', 12);
        $response->assertJsonPath('data.margins.continuous.top', 6);
        $response->assertJsonPath('data.margins.half.top', 6);
    }

    public function test_update_requires_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->putJson('/api/v1/invoice-print-settings', ['scale_percent' => 90]);

        $response->assertForbidden();
    }

    public function test_update_persists_and_is_reflected_by_a_later_read(): void
    {
        Permission::query()->firstOrCreate(['name' => 'administration.invoice_print_settings.update', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('administration.invoice_print_settings.update');
        Sanctum::actingAs($user);

        // margins replaces the whole JSON column (Eloquent update() doesn't deep-merge array
        // casts) — the frontend settings form always submits the complete 3-paper-type object,
        // never a partial patch, so this sends the full shape too.
        $response = $this->putJson('/api/v1/invoice-print-settings', [
            'orientation' => 'landscape',
            'margins' => [
                'a4' => ['top' => 15, 'bottom' => 15, 'left' => 15, 'right' => 15],
                'continuous' => ['top' => 6, 'bottom' => 6, 'left' => 6, 'right' => 6],
                'half' => ['top' => 6, 'bottom' => 6, 'left' => 6, 'right' => 6],
            ],
            'scale_percent' => 90,
            'visible_columns' => ['description', 'qty', 'lineAmt'],
            'show_logo' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.orientation', 'landscape');
        $response->assertJsonPath('data.margins.a4.top', 15);
        $response->assertJsonPath('data.scale_percent', 90);
        $response->assertJsonPath('data.visible_columns', ['description', 'qty', 'lineAmt']);
        $response->assertJsonPath('data.show_logo', true);

        $this->getJson('/api/v1/invoice-print-settings')->assertJsonPath('data.scale_percent', 90);
    }

    public function test_visible_columns_cannot_be_emptied(): void
    {
        Permission::query()->firstOrCreate(['name' => 'administration.invoice_print_settings.update', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('administration.invoice_print_settings.update');
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/invoice-print-settings', ['visible_columns' => []]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('visible_columns');
    }
}
