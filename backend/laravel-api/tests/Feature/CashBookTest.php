<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Services\PaymentEntryService;
use App\Services\ReceiptEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Cash Book Transaction — document-level screen data (CashBookController), one row per Receipt/Payment Entry. */
class CashBookTest extends TestCase
{
    use RefreshDatabase;

    protected ReceiptEntryService $receiptEntryService;
    protected PaymentEntryService $paymentEntryService;
    protected Customer $customer;
    protected ChartOfAccount $bankAccount;
    protected ChartOfAccount $expenseAccount;
    protected Branch $branchA;
    protected Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->receiptEntryService = app(ReceiptEntryService::class);
        $this->paymentEntryService = app(PaymentEntryService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branchA = Branch::query()->create(['company_id' => $company->id, 'name' => 'Branch A', 'code' => 'BA']);
        $this->branchB = Branch::query()->create(['company_id' => $company->id, 'name' => 'Branch B', 'code' => 'BB']);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $this->bankAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $this->expenseAccount = ChartOfAccount::query()->where('code', '6000')->firstOrFail();

        Permission::query()->firstOrCreate(['name' => 'accounting.journal_list.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.journal_list.view');
        Sanctum::actingAs($user);
    }

    protected function submittedReceipt(float $amount, ?string $branchId = null): void
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->bankAccount->id,
            'branch_id' => $branchId,
            'total_amount' => $amount,
        ]);
        $this->receiptEntryService->submit($receipt);
    }

    protected function submittedPayment(float $amount, ?string $branchId = null): void
    {
        $payment = $this->paymentEntryService->create([
            'payment_type' => 'general_expense',
            'expense_account_id' => $this->expenseAccount->id,
            'description' => 'Office supplies',
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->bankAccount->id,
            'branch_id' => $branchId,
        ]);
        $this->paymentEntryService->submit($payment);
    }

    public function test_all_view_returns_both_receipts_and_payments(): void
    {
        $this->submittedReceipt(50000);
        $this->submittedPayment(15000);

        $response = $this->get('/api/v1/cash-book?view=all')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_receipt_view_returns_only_receipts(): void
    {
        $this->submittedReceipt(50000);
        $this->submittedPayment(15000);

        $response = $this->get('/api/v1/cash-book?view=receipt')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('receipt', $rows[0]['type']);
        $this->assertEquals(50000, $rows[0]['debit']);
        $this->assertEquals(0, $rows[0]['credit']);
    }

    public function test_payment_view_returns_only_payments(): void
    {
        $this->submittedReceipt(50000);
        $this->submittedPayment(15000);

        $response = $this->get('/api/v1/cash-book?view=payment')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('payment', $rows[0]['type']);
        $this->assertEquals(15000, $rows[0]['credit']);
        $this->assertEquals(0, $rows[0]['debit']);
    }

    public function test_branch_filter_narrows_to_the_documents_own_branch(): void
    {
        $this->submittedReceipt(10000, $this->branchA->id);
        $this->submittedReceipt(25000, $this->branchB->id);

        $response = $this->get("/api/v1/cash-book?view=all&branch_id={$this->branchA->id}")->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(10000, $rows[0]['debit']);
    }

    public function test_date_range_filter_excludes_rows_outside_the_range(): void
    {
        $this->submittedReceipt(10000);

        $response = $this->get('/api/v1/cash-book?view=all&date_from=2000-01-01&date_to=2000-01-31')->assertOk();

        $this->assertEquals(0, $response->json('meta.total'));
    }

    public function test_search_matches_party_name(): void
    {
        $this->submittedReceipt(10000);

        $response = $this->get('/api/v1/cash-book?view=all&search=Acme')->assertOk();

        $this->assertEquals(1, $response->json('meta.total'));

        $response = $this->get('/api/v1/cash-book?view=all&search=Nonexistent')->assertOk();
        $this->assertEquals(0, $response->json('meta.total'));
    }

    public function test_pagination_meta_reflects_per_page(): void
    {
        $this->submittedReceipt(10000);
        $this->submittedReceipt(20000);
        $this->submittedReceipt(30000);

        $response = $this->get('/api/v1/cash-book?view=receipt&per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(3, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.last_page'));
    }
}
