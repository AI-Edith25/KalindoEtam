<?php

namespace Tests\Feature\Import;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\TermsOfPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import Wizard — Customers module. terms_of_payment_id is an OPTIONAL fk (unlike
 * Item's item_group_id/uom_id) — an unresolved TermCode must null the field out and
 * only warn, never fail the row (no auto-create, no manual fk-resolutions call at all).
 */
class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'master.customers.import', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('master.customers.import');
        Sanctum::actingAs($user);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function mapping(): array
    {
        return [
            'mapping' => [
                'CusCode' => 'customer_code',
                'CusName' => 'customer_name',
                'Tel' => 'phone',
                'Email' => 'email',
                'TermCode' => 'terms_of_payment_id',
                'CreditLimit' => 'credit_limit',
                '_address' => 'address',
            ],
        ];
    }

    public function test_happy_path_resolves_terms_of_payment_by_code(): void
    {
        TermsOfPayment::query()->create(['code' => 'COD', 'name' => 'Cash On Delivery', 'days' => 0]);

        $csv = "CusCode,CusName,Tel,Email,TermCode,CreditLimit,Address1,Address2,Address3,Address4\n"
            ."CUS-001,PT Sample Customer,0541-111,cust@example.com,COD,50000000,Jl. Sample,,,\n";

        $upload = $this->post('/api/v1/import/customers/batches', ['file' => $this->csvFile('customers.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 1, 'valid' => 1, 'warning' => 0, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $customer = Customer::query()->where('customer_code', 'CUS-001')->first();
        $this->assertSame('COD', $customer->termsOfPayment->code);
        $this->assertSame('50000000.00', $customer->credit_limit);
        $this->assertSame('Jl. Sample', $customer->address);
    }

    public function test_unresolved_optional_term_code_nulls_the_field_and_only_warns(): void
    {
        $csv = "CusCode,CusName,Tel,Email,TermCode,CreditLimit,Address1,Address2,Address3,Address4\n"
            ."CUS-002,PT No Terms,,,DOES-NOT-EXIST,,,,,\n";

        $upload = $this->post('/api/v1/import/customers/batches', ['file' => $this->csvFile('customers.csv', $csv)]);
        $batchId = $upload->json('data.batch.id');

        $this->patchJson("/api/v1/import/batches/{$batchId}/mapping", $this->mapping())->assertOk();

        $preview = $this->postJson("/api/v1/import/batches/{$batchId}/preview");
        $this->assertSame(['total' => 1, 'valid' => 0, 'warning' => 1, 'error' => 0], $preview->json('data.summary'));

        $this->postJson("/api/v1/import/batches/{$batchId}/commit", [
            'write_mode' => 'upsert',
            'commit_mode' => 'skip_invalid',
        ])->assertOk();

        $customer = Customer::query()->where('customer_code', 'CUS-002')->first();
        $this->assertNotNull($customer);
        $this->assertNull($customer->terms_of_payment_id);
    }
}
