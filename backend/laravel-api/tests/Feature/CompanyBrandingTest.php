<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_without_company_view_still_sees_branding_name_only(): void
    {
        Company::query()->create([
            'name' => 'Acme Real Name',
            'code' => 'ACME',
            'address' => 'Confidential Street 1',
            'npwp' => '01.234.567.8-901.000',
            'fiscal_year_start' => now()->startOfYear()->toDateString(),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/company/branding');

        $response->assertOk();
        $response->assertExactJson([
            'success' => true,
            'message' => '',
            'data' => ['name' => 'Acme Real Name', 'logo_url' => null],
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/company/branding')->assertUnauthorized();
    }
}
