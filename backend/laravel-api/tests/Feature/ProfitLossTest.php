<?php

namespace Tests\Feature;

use App\Enums\AccountType;
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
use App\Services\ProfitLossService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Database\Seeders\ReportAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProfitLossTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected JournalEntryService $journalEntryService;
    protected GeneralLedgerService $generalLedgerService;
    protected ProfitLossService $profitLossService;
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

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->journalEntryService = app(JournalEntryService::class);
        $this->generalLedgerService = app(GeneralLedgerService::class);
        $this->profitLossService = app(ProfitLossService::class);

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

    public function test_revenue_section_nets_sales_revenue_against_discount_given(): void
    {
        // Sales Revenue (4000) 100000, Discount Given (4900) 10000 — both mapped to the
        // 'revenue' section (docs/PROFIT_LOSS_DESIGN.md §4), so the section nets to 90000.
        $this->submittedInvoice(qty: 5, rate: 20000, discountAmount: 10000);

        $result = $this->profitLossService->summarize(['date_from' => now()->startOfYear()->toDateString()]);
        $revenue = collect($result['sections'])->firstWhere('key', 'revenue');

        $this->assertEquals(90000.0, $revenue['subtotal']);
        $codes = collect($revenue['lines'])->map(fn ($line) => $line['account']->code)->all();
        $this->assertContains('4000', $codes);
        $this->assertContains('4900', $codes);
    }

    public function test_cost_of_goods_sold_and_operating_expense_sections_sum_their_mapped_accounts(): void
    {
        $this->postManualExpense($this->cogsAccount, 30000);
        $this->postManualExpense($this->opexAccount, 12000);

        $result = $this->profitLossService->summarize(['date_from' => now()->startOfYear()->toDateString()]);
        $cogs = collect($result['sections'])->firstWhere('key', 'cost_of_goods_sold');
        $opex = collect($result['sections'])->firstWhere('key', 'operating_expense');

        $this->assertEquals(30000.0, $cogs['subtotal']);
        $this->assertEquals(12000.0, $opex['subtotal']);
    }

    public function test_gross_profit_operating_income_and_net_profit_arithmetic(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // Revenue 100000
        $this->postManualExpense($this->cogsAccount, 40000);
        $this->postManualExpense($this->opexAccount, 15000);

        $result = $this->profitLossService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        $this->assertEquals(60000.0, $result['gross_profit']); // 100000 - 40000
        $this->assertEquals(45000.0, $result['operating_income']); // 60000 - 15000
        $this->assertEquals(45000.0, $result['net_profit_before_tax']); // no other income/expense
        $this->assertNull($result['tax']);
        $this->assertEquals(45000.0, $result['net_profit']); // net_profit_before_tax - 0
    }

    public function test_period_movement_only_does_not_leak_prior_periods_income(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: '2026-01-10'); // 100000, January

        // A June-only window must show none of January's revenue — proves this report uses
        // period movement, not a cumulative balance (docs/PROFIT_LOSS_DESIGN.md §3).
        $juneOnly = $this->profitLossService->summarize(['date_from' => '2026-06-01', 'date_to' => '2026-06-30']);
        $juneRevenue = collect($juneOnly['sections'])->firstWhere('key', 'revenue');
        $this->assertEquals(0.0, $juneRevenue['subtotal']);
        $this->assertEmpty($juneRevenue['lines']);

        // The same account's January-inclusive window does show it.
        $fullYear = $this->profitLossService->summarize(['date_from' => '2026-01-01']);
        $yearRevenue = collect($fullYear['sections'])->firstWhere('key', 'revenue');
        $this->assertEquals(100000.0, $yearRevenue['subtotal']);
    }

    public function test_date_from_is_required(): void
    {
        $validator = validator([], (new \App\Http\Requests\IndexProfitLossRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('date_from', $validator->errors()->toArray());
    }

    public function test_zero_movement_mapped_account_omitted_from_lines_but_subtotal_still_renders(): void
    {
        // Nothing posted to Cost of Goods Sold or Operating Expenses this period.
        $this->submittedInvoice(qty: 5, rate: 20000);

        $result = $this->profitLossService->summarize(['date_from' => now()->startOfYear()->toDateString()]);
        $cogs = collect($result['sections'])->firstWhere('key', 'cost_of_goods_sold');

        $this->assertEmpty($cogs['lines']);
        $this->assertEquals(0.0, $cogs['subtotal']);
    }

    public function test_account_with_no_mapping_row_never_appears_and_logs_a_warning(): void
    {
        Log::spy();

        $unmapped = ChartOfAccount::query()->create([
            'code' => '6900',
            'name' => 'Unmapped Expense',
            'account_type' => AccountType::EXPENSE,
            'is_active' => true,
        ]);
        $this->postManualExpense($unmapped, 5000);

        $result = $this->profitLossService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        foreach ($result['sections'] as $section) {
            foreach ($section['lines'] as $line) {
                $this->assertNotEquals('6900', $line['account']->code);
            }
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '6900'))->once();
    }

    public function test_asset_liability_equity_accounts_never_appear_on_the_report(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // touches AR (asset) and Sales Revenue

        $result = $this->profitLossService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        foreach ($result['sections'] as $section) {
            foreach ($section['lines'] as $line) {
                $this->assertNotEquals('1200', $line['account']->code); // Accounts Receivable must never appear
            }
        }
    }

    public function test_empty_period_returns_all_zero_subtotals_without_erroring(): void
    {
        $result = $this->profitLossService->summarize(['date_from' => now()->startOfYear()->toDateString()]);

        foreach ($result['sections'] as $section) {
            $this->assertEquals(0.0, $section['subtotal']);
        }

        $this->assertEquals(0.0, $result['gross_profit']);
        $this->assertEquals(0.0, $result['operating_income']);
        $this->assertEquals(0.0, $result['net_profit_before_tax']);
        $this->assertEquals(0.0, $result['net_profit']);
        $this->assertNull($result['tax']);
    }
}
