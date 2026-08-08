<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\PaymentEntryService;
use App\Services\ReceiptEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Replaces the hardcoded PaymentMethod -> '1100' routing (ReceiptEntry/
 * PaymentEntry::cashAccountCode(), now removed) with a per-record chosen
 * Chart of Accounts row. Proves routing is genuinely dynamic — two entries
 * choosing two different accounts post to two different accounts — and that
 * a non-cash-bank account is rejected server-side, not just filtered client-side.
 */
class CashBankAccountRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected ReceiptEntryService $receiptEntryService;
    protected PaymentEntryService $paymentEntryService;
    protected Customer $customer;
    protected ChartOfAccount $bankMandiri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->receiptEntryService = app(ReceiptEntryService::class);
        $this->paymentEntryService = app(PaymentEntryService::class);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $this->bankMandiri = ChartOfAccount::query()->create([
            'code' => '1101', 'name' => 'Bank Mandiri', 'account_type' => AccountType::ASSET, 'is_cash_bank' => true, 'is_active' => true,
        ]);
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_receipt_entry_posts_journal_to_its_chosen_cash_account(): void
    {
        $receiptEntry = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->bankMandiri->id,
            'total_amount' => 100000,
        ]);
        $receiptEntry = $this->receiptEntryService->submit($receiptEntry);

        $lines = $receiptEntry->fresh()->journalLines();
        $this->assertEquals('1101', $lines[0]['account']);

        $journalEntry = \App\Models\JournalEntry::query()->where('reference_type', 'receipt_entry')->where('reference_id', $receiptEntry->id)->firstOrFail();
        $postedLines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $postedLines->firstWhere('chartOfAccount.code', '1101')->debit);
    }

    public function test_a_second_receipt_entry_posts_to_a_different_account(): void
    {
        $receiptEntry = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'total_amount' => 50000,
        ]);
        $receiptEntry = $this->receiptEntryService->submit($receiptEntry);

        $journalEntry = \App\Models\JournalEntry::query()->where('reference_type', 'receipt_entry')->where('reference_id', $receiptEntry->id)->firstOrFail();
        $postedLines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(50000, (float) $postedLines->firstWhere('chartOfAccount.code', '1100')->debit);
        $this->assertNull($postedLines->firstWhere('chartOfAccount.code', '1101'));
    }

    public function test_payment_entry_general_expense_posts_journal_to_its_chosen_cash_account(): void
    {
        $expenseAccount = ChartOfAccount::query()->where('code', '6100')->firstOrFail();

        $paymentEntry = $this->paymentEntryService->create([
            'payment_type' => 'general_expense',
            'expense_account_id' => $expenseAccount->id,
            'description' => 'Ojek online',
            'amount' => 75000,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->bankMandiri->id,
        ]);
        $paymentEntry = $this->paymentEntryService->submit($paymentEntry);

        $journalEntry = \App\Models\JournalEntry::query()->where('reference_type', 'payment_entry')->where('reference_id', $paymentEntry->id)->firstOrFail();
        $postedLines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(75000, (float) $postedLines->firstWhere('chartOfAccount.code', '1101')->credit);
    }

    public function test_store_receipt_entry_rejects_non_cash_bank_account(): void
    {
        $accountsReceivable = $this->accountId('1200'); // Accounts Receivable, not flagged is_cash_bank

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['customer_id' => $this->customer->id, 'receipt_date' => now()->toDateString(), 'cash_account_id' => $accountsReceivable, 'total_amount' => 100000],
            (new \App\Http\Requests\StoreReceiptEntryRequest())->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('cash_account_id', $validator->errors()->toArray());
    }

    public function test_store_payment_entry_rejects_non_cash_bank_account(): void
    {
        $expenseAccount = ChartOfAccount::query()->where('code', '6100')->firstOrFail();
        $accountsReceivable = $this->accountId('1200');

        $validator = \Illuminate\Support\Facades\Validator::make(
            [
                'payment_type' => 'general_expense',
                'expense_account_id' => $expenseAccount->id,
                'description' => 'Test',
                'amount' => 50000,
                'payment_date' => now()->toDateString(),
                'cash_account_id' => $accountsReceivable,
            ],
            (new \App\Http\Requests\StorePaymentEntryRequest())->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('cash_account_id', $validator->errors()->toArray());
    }

    public function test_store_receipt_entry_requires_cash_account_id(): void
    {
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['customer_id' => $this->customer->id, 'receipt_date' => now()->toDateString(), 'total_amount' => 100000],
            (new \App\Http\Requests\StoreReceiptEntryRequest())->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('cash_account_id', $validator->errors()->toArray());
    }
}
