<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ReportStatementType;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Http\Requests\IndexCashFlowRequest;
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
use App\Services\CashFlowService;
use App\Services\DeliveryService;
use App\Services\GeneralLedgerService;
use App\Services\InvoiceService;
use App\Services\JournalEntryService;
use App\Services\ProfitLossService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\CashFlowMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Database\Seeders\ReportAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected JournalEntryService $journalEntryService;
    protected GeneralLedgerService $generalLedgerService;
    protected ProfitLossService $profitLossService;
    protected CashFlowService $cashFlowService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected ChartOfAccount $arAccount;
    protected ChartOfAccount $cashAccount;
    protected ChartOfAccount $ownerEquityAccount;
    protected ChartOfAccount $opexAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ReportAccountMappingSeeder::class);
        $this->seed(CashFlowMappingSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->journalEntryService = app(JournalEntryService::class);
        $this->generalLedgerService = app(GeneralLedgerService::class);
        $this->profitLossService = app(ProfitLossService::class);
        $this->cashFlowService = app(CashFlowService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
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
        $this->ownerEquityAccount = ChartOfAccount::query()->where('code', '3000')->firstOrFail();
        $this->opexAccount = ChartOfAccount::query()->where('code', '6000')->firstOrFail();
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
            'discount_amount' => 0,
        ]);

        return $this->invoiceService->submit($invoice);
    }

    /** Debits the given expense account / credits Cash — for seeding Operating Expense activity that actually moves cash. */
    protected function postManualExpense(ChartOfAccount $expenseAccount, float $amount, ?string $postingDate = null): JournalEntry
    {
        return $this->postManualJournalEntry($expenseAccount, $this->cashAccount, $amount, $postingDate);
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

    public function test_date_from_is_required(): void
    {
        $validator = validator([], (new IndexCashFlowRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('date_from', $validator->errors()->toArray());
    }

    public function test_net_profit_is_obtained_directly_from_profit_loss_service_not_reimplemented(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000);
        $this->postManualExpense($this->opexAccount, 10000);

        $dateFrom = now()->startOfYear()->toDateString();
        $dateTo = now()->toDateString();

        $result = $this->cashFlowService->summarize(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $expectedNetProfit = $this->profitLossService->summarize(['date_from' => $dateFrom, 'date_to' => $dateTo])['net_profit'];

        $this->assertEquals($expectedNetProfit, $result['net_profit']);
    }

    public function test_operating_adjustment_reflects_increase_in_accounts_receivable_as_a_cash_use(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // AR +100000, never touches Cash

        $result = $this->cashFlowService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        $arLine = collect($result['operating']['lines'])->first(fn ($line) => $line['account']->code === '1200');
        $this->assertNotNull($arLine);
        $this->assertEquals(-100000.0, $arLine['amount']); // increase in AR displays as a negative (cash-use) adjustment
    }

    public function test_financing_activity_reflects_increase_in_owners_equity_as_a_cash_source(): void
    {
        $this->postManualJournalEntry($this->cashAccount, $this->ownerEquityAccount, 50000); // owner injects capital

        $result = $this->cashFlowService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        $this->assertEquals(50000.0, $result['financing']['net_cash']);
        $this->assertEquals(50000.0, $result['closing_cash']);
        $this->assertTrue($result['is_balanced']);
    }

    public function test_opening_cash_plus_net_cash_movement_equals_closing_cash(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // Revenue 100000, AR +100000
        $this->postManualJournalEntry($this->cashAccount, $this->arAccount, 60000); // collect part of the receivable: Cash +60000, AR -60000
        $this->postManualExpense($this->opexAccount, 15000); // Cash -15000

        $result = $this->cashFlowService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        // Net Profit 100000 (revenue only, no COGS/Opex reduces revenue here besides the 15000 Opex) - 15000 = 85000
        $this->assertEquals(85000.0, $result['net_profit']);
        // AR net change = +100000 (invoice) - 60000 (collection) = +40000 -> Operating Adjustment -40000
        $this->assertEquals(45000.0, $result['operating']['net_cash']); // 85000 - 40000
        $this->assertEquals(0.0, $result['investing']['net_cash']);
        $this->assertEquals(0.0, $result['financing']['net_cash']);
        $this->assertEquals(45000.0, $result['net_cash_movement']);
        $this->assertEquals(0.0, $result['opening_cash']);
        $this->assertEquals(45000.0, $result['closing_cash']);
        $this->assertEquals(round($result['opening_cash'] + $result['net_cash_movement'], 2), $result['closing_cash']);
        $this->assertTrue($result['is_balanced']);
    }

    public function test_empty_period_still_balances_at_zero(): void
    {
        $result = $this->cashFlowService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        $this->assertTrue($result['is_balanced']);
        $this->assertEquals(0.0, $result['net_profit']);
        $this->assertEquals(0.0, $result['net_cash_movement']);
        $this->assertEquals(0.0, $result['opening_cash']);
        $this->assertEquals(0.0, $result['closing_cash']);
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

        $result = $this->cashFlowService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        foreach ([$result['operating'], $result['investing'], $result['financing']] as $section) {
            foreach ($section['lines'] as $line) {
                $this->assertNotEquals('1400', $line['account']->code);
            }
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '1400'))->once();
    }

    public function test_a_broken_accounting_equation_logs_a_warning(): void
    {
        Log::spy();

        // Deletes Sales Revenue's P&L mapping so Net Profit for the Period no longer includes it,
        // while Accounts Receivable's own Operating Adjustment still reflects the full increase —
        // the report must still render (not throw), flagged rather than silently shown as balanced.
        ReportAccountMapping::query()
            ->whereHas('chartOfAccount', fn ($q) => $q->where('code', '4000'))
            ->where('statement_type', ReportStatementType::PROFIT_LOSS->value)
            ->delete();

        $this->submittedInvoice(qty: 5, rate: 20000);

        $result = $this->cashFlowService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        $this->assertFalse($result['is_balanced']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'does not balance'))->once();
    }
}
