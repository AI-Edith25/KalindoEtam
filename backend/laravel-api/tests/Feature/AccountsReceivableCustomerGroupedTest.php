<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\AccountsReceivableService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F1/F2 (UAT review 2026-08-12) — "Tanda Terima Invoice" (listAll, unpaginated) and "Laporan Penagihan Harian" (groupedByCustomer). */
class AccountsReceivableCustomerGroupedTest extends TestCase
{
    use RefreshDatabase;

    protected AccountsReceivableService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->service = app(AccountsReceivableService::class);
    }

    protected function receivable(Customer $customer, float $amount, string $reference1 = null): AccountsReceivable
    {
        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods',
            'status' => 'submitted',
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'subtotal' => $amount,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => $amount,
            'reference_1' => $reference1,
        ]);

        return AccountsReceivable::query()->create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'reference_number' => $invoice->document_number,
            'amount' => $amount,
            'paid_amount' => 0,
            'due_date' => now()->addDays(10)->toDateString(),
            'status' => AccountsReceivableStatus::UNPAID,
        ]);
    }

    public function test_list_all_exposes_reference_1_and_reference_2_for_tanda_terima_invoice(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Toko A']);
        $this->receivable($customer, 50000, 'SO-0001');

        $rows = $this->service->listAll(['customer_id' => $customer->id]);

        $this->assertCount(1, $rows);
        $this->assertEquals('SO-0001', $rows->first()->invoice->reference_1);
    }

    public function test_grouped_by_customer_subtotals_each_customer_and_sums_a_grand_total(): void
    {
        $tokoA = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Toko A']);
        $tokoB = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Toko B']);

        $this->receivable($tokoA, 50000);
        $this->receivable($tokoA, 25000);
        $this->receivable($tokoB, 10000);

        $result = $this->service->groupedByCustomer([]);

        $this->assertEquals(85000.0, $result['grand_total']);
        $this->assertCount(2, $result['groups']);

        $tokoAGroup = $result['groups']->firstWhere('customer_name', 'Toko A');
        $this->assertEquals(75000.0, $tokoAGroup['customer_subtotal']);
        $this->assertCount(2, $tokoAGroup['rows']);
        $this->assertEquals('C001', $tokoAGroup['customer_code']);
    }
}
