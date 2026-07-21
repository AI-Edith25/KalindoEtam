<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ReportStatementType;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Http\Requests\IndexBalanceSheetRequest;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\ReportAccountMapping;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\BalanceSheetService;
use App\Services\DeliveryService;
use App\Services\GeneralLedgerService;
use App\Services\InvoiceService;
use App\Services\JournalEntryService;
use App\Services\ProfitLossService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\BalanceSheetMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Database\Seeders\ReportAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected JournalEntryService $journalEntryService;
    protected GeneralLedgerService $generalLedgerService;
    protected ProfitLossService $profitLossService;
    protected BalanceSheetService $balanceSheetService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected ChartOfAccount $arAccount;
    protected ChartOfAccount $cashAccount;
    protected ChartOfAccount $revenueAccount;
    protected ChartOfAccount $cogsAccount;
    protected ChartOfAccount $opexAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ReportAccountMappingSeeder::class);
        $this->seed(BalanceSheetMappingSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->journalEntryService = app(JournalEntryService::class);
        $this->generalLedgerService = app(GeneralLedgerService::class);
        $this->profitLossService = app(ProfitLossService::class);
        $this->balanceSheetService = app(BalanceSheetService::class);

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

        app(StockLedgerService::class)->record(
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
        $this->cogsAccount = ChartOfAccount::query()->where('code', '5000')->firstOrFail();
        $this->opexAccount = ChartOfAccount::query()->where('code', '6000')->firstOrFail();
    }

    protected function submittedInvoice(int $qty = 10, float $rate = 20000, float $taxAmount = 0, ?string $invoiceDate = null, float $discountAmount = 0): Invoice
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
            'discount_amount' => $discountAmount,
        ]);

        return $this->invoiceService->submit($invoice);
    }

    /** Debits the given expense account / credits Cash — for seeding Cost of Goods Sold / Operating Expense activity directly. */
    protected function postManualExpense(ChartOfAccount $expenseAccount, float $amount, ?string $postingDate = null): JournalEntry
    {
        $journalEntry = $this->journalEntryService->create([
            'posting_date' => $postingDate ?? now()->toDateString(),
            'description' => 'Manual expense',
            'lines' => [
                ['chart_of_account_id' => $expenseAccount->id, 'debit' => $amount, 'credit' => 0],
                ['chart_of_account_id' => $this->cashAccount->id, 'debit' => 0, 'credit' => $amount],
            ],
        ]);

        $this->approveDocument($journalEntry);

        return $this->journalEntryService->post($journalEntry);
    }

    protected function postManualJournalEntry(ChartOfAccount $debitAccount, ChartOfAccount $creditAccount, float $amount, ?string $postingDate = null): JournalEntry
    {
        $journalEntry = $this->journalEntryService->create([
            'posting_date' => $postingDate ?? now()->toDateString(),
            'description' => 'Manual posting',
            'lines' => [
                ['chart_of_account_id' => $debitAccount->id, 'debit' => $amount, 'credit' => 0],
                ['chart_of_account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $amount],
            ],
        ]);

        $this->approveDocument($journalEntry);

        return $this->journalEntryService->post($journalEntry);
    }

    public function test_as_of_date_is_required(): void
    {
        $validator = validator([], (new IndexBalanceSheetRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('as_of_date', $validator->errors()->toArray());
    }

    public function test_accounts_are_grouped_into_their_mapped_balance_sheet_sections(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // touches AR (asset) and Sales Revenue

        $result = $this->balanceSheetService->summarize(['as_of_date' => now()->toDateString()]);

        $currentAssets = collect($result['sections'])->firstWhere('key', 'current_asset');
        $codes = collect($currentAssets['lines'])->map(fn ($line) => $line['account']->code)->all();
        $this->assertContains('1200', $codes);

        // Revenue/Expense accounts (Profit & Loss's own domain) never appear on a Balance Sheet.
        $allCodes = collect($result['sections'])
            ->flatMap(fn ($section) => collect($section['lines'])->map(fn ($line) => $line['account']->code))
            ->all();
        $this->assertNotContains('4000', $allCodes);
    }

    public function test_total_assets_always_equals_total_liabilities_plus_equity(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 5000); // Revenue 100000, Tax Payable 5000, AR 105000
        $this->postManualExpense($this->cogsAccount, 20000);

        $result = $this->balanceSheetService->summarize(['as_of_date' => now()->toDateString()]);

        $this->assertTrue($result['is_balanced']);
        $this->assertEquals($result['total_assets'], $result['total_liabilities_and_equity']);
    }

    public function test_empty_chart_still_balances_at_zero(): void
    {
        $result = $this->balanceSheetService->summarize(['as_of_date' => now()->toDateString()]);

        $this->assertTrue($result['is_balanced']);
        $this->assertEquals(0.0, $result['total_assets']);
        $this->assertEquals(0.0, $result['total_liabilities_and_equity']);
        $this->assertEquals(0.0, $result['current_year_profit']);
        $this->assertEquals(0.0, $result['retained_earnings']);
    }

    public function test_current_year_profit_is_obtained_directly_from_profit_loss_service_not_reimplemented(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000);
        $this->postManualExpense($this->opexAccount, 10000);

        $asOfDate = now()->toDateString();
        $fiscalYearStart = now()->startOfYear()->toDateString();

        $result = $this->balanceSheetService->summarize(['as_of_date' => $asOfDate]);
        $expectedNetProfit = $this->profitLossService->summarize(['date_from' => $fiscalYearStart, 'date_to' => $asOfDate])['net_profit'];

        $this->assertEquals($expectedNetProfit, $result['current_year_profit']);
    }

    public function test_retained_earnings_folds_in_prior_fiscal_years_profit_kept_separate_from_current_year(): void
    {
        $priorYearDate = now()->subYear()->startOfMonth()->toDateString();
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: $priorYearDate); // 100000, prior fiscal year
        $this->submittedInvoice(qty: 3, rate: 20000); // 60000, current fiscal year

        $result = $this->balanceSheetService->summarize(['as_of_date' => now()->toDateString()]);

        $this->assertEquals(60000.0, $result['current_year_profit']);
        $this->assertEquals(100000.0, $result['retained_earnings']); // mapped 3100 balance (0) + prior years' profit (100000)
    }

    public function test_account_with_no_mapping_row_never_appears_and_logs_a_warning(): void
    {
        Log::spy();

        $unmapped = ChartOfAccount::query()->create([
            'code' => '1400',
            'name' => 'Unmapped Asset',
            'account_type' => AccountType::ASSET,
            'is_active' => true,
        ]);
        $this->postManualJournalEntry($unmapped, $this->cashAccount, 5000);

        $result = $this->balanceSheetService->summarize(['as_of_date' => now()->toDateString()]);

        foreach ($result['sections'] as $section) {
            foreach ($section['lines'] as $line) {
                $this->assertNotEquals('1400', $line['account']->code);
            }
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '1400'))->once();
    }

    public function test_a_broken_accounting_equation_logs_a_warning(): void
    {
        Log::spy();

        // Deletes the offsetting Sales Revenue mapping row so Accounts Receivable's asset-side
        // balance has no equity/revenue counterpart on the report — the report must still render
        // (not throw), flagged rather than silently shown as balanced.
        ReportAccountMapping::query()
            ->whereHas('chartOfAccount', fn ($q) => $q->where('code', '4000'))
            ->where('statement_type', ReportStatementType::PROFIT_LOSS->value)
            ->delete();

        $this->submittedInvoice(qty: 5, rate: 20000);

        $result = $this->balanceSheetService->summarize(['as_of_date' => now()->toDateString()]);

        $this->assertFalse($result['is_balanced']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'does not balance'))->once();
    }
}
