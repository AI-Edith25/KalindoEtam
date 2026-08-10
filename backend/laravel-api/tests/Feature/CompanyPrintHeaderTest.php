<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyPrintHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_without_company_view_still_sees_print_header_fields(): void
    {
        Company::query()->create([
            'name' => 'Acme Real Name',
            'code' => 'ACME',
            'address' => 'Confidential Street 1',
            'phone' => '0541-123456',
            'email' => 'acme@example.com',
            'npwp' => '01.234.567.8-901.000',
            'fiscal_year_start' => now()->startOfYear()->toDateString(),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/company/print-header');

        $response->assertOk();
        $response->assertExactJson([
            'success' => true,
            'message' => '',
            'data' => [
                'name' => 'Acme Real Name',
                'address' => 'Confidential Street 1',
                'phone' => '0541-123456',
                'email' => 'acme@example.com',
                'npwp' => '01.234.567.8-901.000',
            ],
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/company/print-header')->assertUnauthorized();
    }
}
