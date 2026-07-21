<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\GeneralLedgerService;
use App\Services\InvoiceService;
use App\Services\JournalEntryService;
use App\Services\SalesOrderService;
use App\Services\TrialBalanceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected JournalEntryService $journalEntryService;
    protected GeneralLedgerService $generalLedgerService;
    protected TrialBalanceService $trialBalanceService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected ChartOfAccount $arAccount;
    protected ChartOfAccount $cashAccount;
    protected ChartOfAccount $revenueAccount;
    protected ChartOfAccount $taxAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->journalEntryService = app(JournalEntryService::class);
        $this->generalLedgerService = app(GeneralLedgerService::class);
        $this->trialBalanceService = app(TrialBalanceService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['branch_id' => $branch->id, 'name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        app(\App\Services\StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 1000,
            postingDatetime: now(),
        );

        $this->arAccount = ChartOfAccount::query()->where('code', '1200')->firstOrFail();
        $this->cashAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $this->revenueAccount = ChartOfAccount::query()->where('code', '4000')->firstOrFail();
        $this->taxAccount = ChartOfAccount::query()->where('code', '2100')->firstOrFail();
    }

    protected function submittedInvoice(int $qty = 10, float $rate = 20000, float $taxAmount = 0, ?string $invoiceDate = null): Invoice
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $this->deliveryService->submit($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => $invoiceDate ?? now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => $taxAmount,
        ]);

        return $this->invoiceService->submit($invoice);
    }

    /** Debits AR / credits Revenue directly — for edge cases needing a specific posting_date. */
    protected function postManualJournalEntry(string $postingDate, float $amount): JournalEntry
    {
        $journalEntry = $this->journalEntryService->create([
            'posting_date' => $postingDate,
            'description' => 'Manual entry',
            'lines' => [
                ['chart_of_account_id' => $this->arAccount->id, 'debit' => $amount, 'credit' => 0],
                ['chart_of_account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => $amount],
            ],
        ]);

        $this->approveDocument($journalEntry);

        return $this->journalEntryService->post($journalEntry);
    }

    /** Credits AR / debits Cash — simulates an overpayment, pushing a debit-normal account's ending balance negative. */
    protected function postManualCreditToAr(float $amount): JournalEntry
    {
        $journalEntry = $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'description' => 'Overpayment',
            'lines' => [
                ['chart_of_account_id' => $this->cashAccount->id, 'debit' => $amount, 'credit' => 0],
                ['chart_of_account_id' => $this->arAccount->id, 'debit' => 0, 'credit' => $amount],
            ],
        ]);

        $this->approveDocument($journalEntry);

        return $this->journalEntryService->post($journalEntry);
    }

    public function test_debit_normal_account_with_positive_balance_places_entirely_in_debit(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // AR ending_balance = 100000, debit-normal

        $result = $this->trialBalanceService->summarize([]);
        $row = collect($result['rows'])->firstWhere('account.code', '1200');

        $this->assertEquals(100000.0, $row['debit']);
        $this->assertEquals(0.0, $row['credit']);
    }

    public function test_credit_normal_account_with_positive_balance_places_entirely_in_credit(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // Revenue ending_balance = 100000, credit-normal

        $result = $this->trialBalanceService->summarize([]);
        $row = collect($result['rows'])->firstWhere('account.code', '4000');

        $this->assertEquals(0.0, $row['debit']);
        $this->assertEquals(100000.0, $row['credit']);
    }

    public function test_debit_normal_account_with_negative_balance_places_in_credit(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // AR = 100000
        $this->postManualCreditToAr(150000); // AR ending_balance = -50000 — overpaid receivable

        $result = $this->trialBalanceService->summarize([]);
        $row = collect($result['rows'])->firstWhere('account.code', '1200');

        $this->assertEquals(0.0, $row['debit']);
        $this->assertEquals(50000.0, $row['credit']);
    }

    public function test_credit_normal_account_with_negative_balance_places_in_debit(): void
    {
        // Directly overdraw Tax Payable (credit-normal) into a debit balance.
        $journalEntry = $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'description' => 'Tax adjustment',
            'lines' => [
                ['chart_of_account_id' => $this->taxAccount->id, 'debit' => 30000, 'credit' => 0],
                ['chart_of_account_id' => $this->cashAccount->id, 'debit' => 0, 'credit' => 30000],
            ],
        ]);
        $this->approveDocument($journalEntry);
        $this->journalEntryService->post($journalEntry);

        $result = $this->trialBalanceService->summarize([]);
        $row = collect($result['rows'])->firstWhere('account.code', '2100');

        $this->assertEquals(30000.0, $row['debit']);
        $this->assertEquals(0.0, $row['credit']);
    }

    public function test_zero_balance_account_places_zero_in_both_columns(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000);

        $result = $this->trialBalanceService->summarize([]);
        $untouched = collect($result['rows'])->firstWhere('account.code', '5100'); // Purchase Expense, never touched

        $this->assertEquals(0.0, $untouched['debit']);
        $this->assertEquals(0.0, $untouched['credit']);
    }

    public function test_total_debit_equals_total_credit_and_is_balanced_over_a_mixed_chain(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 5000);
        $this->submittedInvoice(qty: 3, rate: 20000);

        $result = $this->trialBalanceService->summarize([]);

        $this->assertEquals($result['total_debit'], $result['total_credit']);
        $this->assertTrue($result['is_balanced']);
    }

    public function test_rows_are_derived_from_the_same_general_ledger_call_not_a_second_calculation(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 5000);
        $this->submittedInvoice(qty: 3, rate: 20000);

        $filters = ['date_from' => now()->startOfYear()->toDateString()];
        $ledgerRows = $this->generalLedgerService->listAccounts($filters);
        $trialBalance = $this->trialBalanceService->summarize($filters);

        foreach ($trialBalance['rows'] as $index => $row) {
            $ledgerRow = $ledgerRows[$index];
            $this->assertEquals($ledgerRow['account']->id, $row['account']->id);

            // The placed debit/credit must reconstruct exactly to the General Ledger's own signed ending_balance.
            $expectedEndingBalance = $row['debit'] > 0 ? $row['debit'] : -$row['credit'];
            $signedExpected = $ledgerRow['account']->isDebitNormal() ? $expectedEndingBalance : -$expectedEndingBalance;
            $this->assertEqualsWithDelta($ledgerRow['ending_balance'], $signedExpected, 0.001);
        }
    }

    public function test_empty_period_returns_all_zero_rows(): void
    {
        // No business document posted anywhere — a genuinely empty period, not just an empty
        // window after real activity (which would still carry a non-zero opening balance forward).
        $result = $this->trialBalanceService->summarize([]);

        $this->assertEquals(0.0, $result['total_debit']);
        $this->assertEquals(0.0, $result['total_credit']);
        $this->assertTrue($result['is_balanced']);
        $this->assertTrue(collect($result['rows'])->every(fn ($row) => $row['debit'] === 0.0 && $row['credit'] === 0.0));
    }

    public function test_backdated_journal_changes_totals_on_recomputation(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: '2026-06-01'); // 100000

        $before = $this->trialBalanceService->summarize(['date_from' => '2026-01-01']);
        $beforeAr = collect($before['rows'])->firstWhere('account.code', '1200');
        $this->assertEquals(100000.0, $beforeAr['debit']);

        $this->postManualJournalEntry('2026-03-01', 25000); // backdated, inside the already-viewed range

        $after = $this->trialBalanceService->summarize(['date_from' => '2026-01-01']);
        $afterAr = collect($after['rows'])->firstWhere('account.code', '1200');
        $this->assertEquals(125000.0, $afterAr['debit']);
    }

    public function test_reversed_journal_pair_nets_to_zero_contribution(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // 100000
        $originalJournal = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->firstOrFail();
        $this->journalEntryService->reverse($originalJournal);

        $result = $this->trialBalanceService->summarize([]);
        $arRow = collect($result['rows'])->firstWhere('account.code', '1200');

        $this->assertEquals(0.0, $arRow['debit']);
        $this->assertEquals(0.0, $arRow['credit']);
        $this->assertTrue($result['is_balanced']);
    }

    public function test_cancelled_invoices_unreversed_journal_still_counts(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // 100000, never paid
        $this->invoiceService->cancel($invoice);

        $result = $this->trialBalanceService->summarize([]);
        $arRow = collect($result['rows'])->firstWhere('account.code', '1200');

        // InvoiceService::cancel() never reverses the Journal Entry (pre-existing, documented
        // behavior) — Trial Balance faithfully shows it exactly as the General Ledger does.
        $this->assertEquals(100000.0, $arRow['debit']);
        $this->assertTrue($result['is_balanced']);
    }
}
