<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\SalesPerson;
use App\Models\TermsOfPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class CustomerListingExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.customers.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.customers.view');
        Sanctum::actingAs($user);
    }

    protected function downloadXlsx(string $query = ''): Worksheet
    {
        $response = $this->get("/api/v1/customers/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'customer-listing').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_layout_matches_the_skybiz_banner_header_hint_data_shape(): void
    {
        $terms = TermsOfPayment::query()->create(['code' => 'COD', 'name' => 'Cash on Delivery', 'days' => 0]);
        $salesPerson = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);
        Customer::query()->create([
            'customer_code' => 'C001',
            'customer_name' => 'Acme',
            'address' => 'Jl. Sample No. 1',
            'area' => 'Balikpapan',
            'terms_of_payment_id' => $terms->id,
            'credit_limit' => 1500000,
            'sales_person_id' => $salesPerson->id,
            'phone' => '081234567890',
            'telephone' => '0541-223344',
            'email' => 'acme@example.com',
            'no_ktp' => '1234567890',
            'no_npwp' => '9876543210',
            'is_active' => true,
        ]);

        $sheet = $this->downloadXlsx();

        $this->assertEquals('Customer', $sheet->getTitle());
        $this->assertEquals('CUSTOMER / DEBTORS', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Advise: Maximum data store not more than 10000 records', $sheet->getCell('C1')->getValue());
        $this->assertStringContainsString('t = text', $sheet->getCell('A2')->getValue());
        $this->assertEquals('compulsary field', $sheet->getCell('E2')->getValue());
        $this->assertNull($sheet->getCell('A3')->getValue());

        $this->assertEquals('CusCode', $sheet->getCell('A4')->getValue());
        $this->assertEquals('CusName', $sheet->getCell('B4')->getValue());
        $this->assertEquals('Status', $sheet->getCell('M4')->getValue());
        $this->assertEquals(Color::COLOR_RED, $sheet->getStyle('A4')->getFont()->getColor()->getARGB());
        $this->assertEquals(Color::COLOR_RED, $sheet->getStyle('B4')->getFont()->getColor()->getARGB());
        $this->assertNotEquals(Color::COLOR_RED, $sheet->getStyle('C4')->getFont()->getColor()->getARGB());

        $this->assertEquals('255 (t)', $sheet->getCell('A5')->getValue());
        $this->assertEquals('15 (n)', $sheet->getCell('F5')->getValue());

        $this->assertEquals('C001', $sheet->getCell('A6')->getValue());
        $this->assertEquals('Acme', $sheet->getCell('B6')->getValue());
        $this->assertEquals('Jl. Sample No. 1', $sheet->getCell('C6')->getValue());
        $this->assertEquals('Balikpapan', $sheet->getCell('D6')->getValue());
        $this->assertEquals('COD', $sheet->getCell('E6')->getValue());
        $this->assertEquals(1500000, $sheet->getCell('F6')->getValue());
        $this->assertEquals('Budi', $sheet->getCell('G6')->getValue());
        $this->assertEquals('081234567890', $sheet->getCell('H6')->getValue());
        $this->assertEquals('Active', $sheet->getCell('M6')->getValue());
        $this->assertEquals('#,##0.00', $sheet->getStyle('F6')->getNumberFormat()->getFormatCode());
    }

    public function test_blank_cells_for_null_fields_and_a_real_zero_credit_limit_is_not_dropped(): void
    {
        Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'No Extras', 'credit_limit' => 0]);

        $sheet = $this->downloadXlsx();

        $this->assertNull($sheet->getCell('C6')->getValue());
        $this->assertEquals(0, $sheet->getCell('F6')->getValue());
        $this->assertEquals('Active', $sheet->getCell('M6')->getValue());
    }

    public function test_search_and_status_filters_are_applied(): void
    {
        Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme', 'is_active' => true]);
        Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Beta', 'is_active' => false]);

        $sheet = $this->downloadXlsx('search=Acme');
        $this->assertEquals('C001', $sheet->getCell('A6')->getValue());
        $this->assertNull($sheet->getCell('A7')->getValue());

        $sheet = $this->downloadXlsx('is_active=false');
        $this->assertEquals('C002', $sheet->getCell('A6')->getValue());
        $this->assertNull($sheet->getCell('A7')->getValue());
    }

    public function test_over_10000_rows_returns_a_validation_error_instead_of_a_file(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10001; $i++) {
            $rows[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'customer_code' => "C{$i}",
                'customer_name' => "Customer {$i}",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            Customer::query()->insert($chunk);
        }

        $response = $this->getJson('/api/v1/customers/export');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('search');
    }
}
