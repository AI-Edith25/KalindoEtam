<?php

namespace Tests\Feature;

use App\Models\AccountsReceivable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Customer Outstanding Bills (live) export: the xlsx must carry the same per-document and grand
 * totals as the JSON list it is exported from, and fully-paid documents must stay out of it.
 */
class OpenBillsByCustomerExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_matches_json_totals_and_skips_paid_documents(): void
    {
        $this->seed(DocumentEngineSeeder::class);
        $company = Company::query()->create(['code' => 'TC', 'name' => 'Test Co', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['code' => 'HQ', 'company_id' => $company->id, 'name' => 'Main']);
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme', 'phone' => '0812', 'credit_limit' => 20000000]);

        $this->makeReceivable($customer, 100000, 40000);
        $this->makeReceivable($customer, 250000, 250000);

        Permission::query()->firstOrCreate(['name' => 'reports.ar_detail.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.ar_detail.view');
        Sanctum::actingAs($user);

        $json = $this->getJson('/api/v1/accounts-receivables/open-by-customer')->assertOk()->json('data');
        $this->assertEquals(60000, $json['grand_total_unpaid']);

        $response = $this->get('/api/v1/accounts-receivables/open-by-customer/export');
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'open-bills').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        $this->assertEquals('Customer Code', $sheet->getCell('A1')->getValue());
        $this->assertEquals('C001', $sheet->getCell('A2')->getValue());
        $this->assertEquals(60000, $sheet->getCell('H2')->getValue());
        $this->assertEquals('', $sheet->getCell('A3')->getValue(), 'only one open document row should exist for C001');
        $this->assertEquals('Grand Total', $sheet->getCell('A4')->getValue());
        $this->assertEquals($json['grand_total_unpaid'], $sheet->getCell('H4')->getValue());
    }

    private function makeReceivable(Customer $customer, float $amount, float $paid): AccountsReceivable
    {
        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods',
            'status' => 'submitted',
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => $amount,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => $amount,
        ]);

        return AccountsReceivable::query()->create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'reference_number' => $invoice->document_number,
            'amount' => $amount,
            'paid_amount' => $paid,
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => $paid >= $amount ? 'paid' : 'unpaid',
        ]);
    }
}
