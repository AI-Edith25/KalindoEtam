<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
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
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GeneralLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected JournalEntryService $journalEntryService;
    protected GeneralLedgerService $generalLedgerService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected ChartOfAccount $arAccount;
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

    /** Posts a plain manual Journal Entry directly, bypassing any business module — for edge cases needing a specific posting_date. */
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

    public function test_opening_balance_is_the_signed_sum_of_everything_before_date_from(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: '2026-01-10'); // 100000, before the range
        $this->submittedInvoice(qty: 3, rate: 20000, invoiceDate: '2026-02-10'); // 60000, inside the range

        $result = $this->generalLedgerService->accountLedger($this->arAccount, ['date_from' => '2026-02-01'], 15);

        $this->assertEquals(100000, $result['opening_balance']); // debit-normal: 100000 debit, 0 credit
        $this->assertEquals(160000, $result['ending_balance']); // 100000 + 60000
    }

    public function test_opening_balance_sign_flips_for_a_credit_normal_account(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 5000, invoiceDate: '2026-01-10');
        $this->submittedInvoice(qty: 3, rate: 20000, taxAmount: 2000, invoiceDate: '2026-02-10');

        $result = $this->generalLedgerService->accountLedger($this->taxAccount, ['date_from' => '2026-02-01'], 15);

        // Tax Payable is credit-normal — opening balance is the credit total from before the range.
        $this->assertEquals(5000, $result['opening_balance']);
        $this->assertEquals(7000, $result['ending_balance']);
    }

    public function test_running_balance_is_deterministically_ordered_by_posting_date_then_document_number_then_id(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: '2026-06-15'); // 100000
        // Posted after, but backdated earlier — must sort first despite being created second.
        $this->postManualJournalEntry('2026-01-01', 30000);

        $result = $this->generalLedgerService->accountLedger($this->arAccount, [], 15);
        $lines = $result['paginator']->items();

        $this->assertCount(2, $lines);
        $this->assertEquals('2026-01-01', $lines[0]->journalEntry->posting_date->toDateString());
        $this->assertEquals(30000, (float) $lines[0]->running_balance);
        $this->assertEquals('2026-06-15', $lines[1]->journalEntry->posting_date->toDateString());
        $this->assertEquals(130000, (float) $lines[1]->running_balance);
    }

    public function test_ending_balance_equals_the_last_lines_running_balance(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // 100000
        $this->submittedInvoice(qty: 2, rate: 20000); // 40000

        $result = $this->generalLedgerService->accountLedger($this->arAccount, [], 15);
        $lines = $result['paginator']->items();
        $lastLine = end($lines);

        $this->assertEquals((float) $lastLine->running_balance, $result['ending_balance']);
        $this->assertEquals(140000, $result['ending_balance']);
    }

    public function test_only_posted_entries_count_by_default_but_draft_can_be_included_via_status_filter(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // 100000, posted

        // A draft manual Journal Entry, never post()ed — should not move any balance by default.
        $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'description' => 'Unposted draft',
            'lines' => [
                ['chart_of_account_id' => $this->arAccount->id, 'debit' => 999999, 'credit' => 0],
                ['chart_of_account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 999999],
            ],
        ]);

        $default = $this->generalLedgerService->accountLedger($this->arAccount, [], 15);
        $this->assertEquals(100000, $default['ending_balance']);
        $this->assertCount(1, $default['paginator']->items());

        $withDrafts = $this->generalLedgerService->accountLedger($this->arAccount, ['status' => DocumentStatus::DRAFT->value], 15);
        $this->assertCount(1, $withDrafts['paginator']->items());
        $this->assertEquals(999999, (float) $withDrafts['paginator']->items()[0]->debit);
    }

    public function test_reversed_journal_shows_both_entries_and_nets_to_zero_movement(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // 100000
        $originalJournal = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->firstOrFail();

        $this->journalEntryService->reverse($originalJournal);

        $result = $this->generalLedgerService->accountLedger($this->arAccount, [], 15);

        $this->assertCount(2, $result['paginator']->items());
        $this->assertEquals(0, $result['ending_balance']); // fully reversed — nets back to zero
    }

    public function test_cancelled_business_document_still_shows_its_posted_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000); // 100000, never paid

        $this->invoiceService->cancel($invoice);
        $this->assertEquals(DocumentStatus::CANCELLED, $invoice->fresh()->status);

        // InvoiceService::cancel() never reverses the Journal Entry (pre-existing, documented
        // behavior) — the Ledger faithfully shows it exactly as posted.
        $result = $this->generalLedgerService->accountLedger($this->arAccount, [], 15);
        $this->assertCount(1, $result['paginator']->items());
        $this->assertEquals(100000, $result['ending_balance']);
    }

    public function test_backdated_journal_changes_a_previously_computed_balance_on_recomputation(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, invoiceDate: '2026-06-01'); // 100000

        $before = $this->generalLedgerService->accountLedger($this->arAccount, ['date_from' => '2026-01-01'], 15);
        $this->assertEquals(100000, $before['ending_balance']);

        // A backdated entry posted afterward, inside an already-viewed range.
        $this->postManualJournalEntry('2026-03-01', 25000);

        $after = $this->generalLedgerService->accountLedger($this->arAccount, ['date_from' => '2026-01-01'], 15);
        $this->assertEquals(125000, $after['ending_balance']);
    }

    public function test_pagination_running_balance_is_continuous_across_pages(): void
    {
        // 5 lines on the AR account, one per invoice.
        for ($i = 1; $i <= 5; $i++) {
            $this->submittedInvoice(qty: 1, rate: 10000 * $i);
        }

        $whole = $this->generalLedgerService->accountLedger($this->arAccount, [], 15);
        $wholeBalances = collect($whole['paginator']->items())->map(fn ($line) => (float) $line->running_balance)->all();

        $paged = [];
        for ($page = 1; $page <= 3; $page++) {
            request()->merge(['page' => $page]);
            $result = $this->generalLedgerService->accountLedger($this->arAccount, [], 2);
            foreach ($result['paginator']->items() as $line) {
                $paged[] = (float) $line->running_balance;
            }
        }

        $this->assertEquals($wholeBalances, $paged);
        $this->assertEquals($whole['ending_balance'], end($paged));
    }

    public function test_large_dataset_paginates_correctly_and_ending_balance_is_independent_of_page(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->submittedInvoice(qty: 1, rate: 1000);
        }

        request()->merge(['page' => 1]);
        $page1 = $this->generalLedgerService->accountLedger($this->arAccount, [], 10);
        request()->merge(['page' => 3]);
        $page3 = $this->generalLedgerService->accountLedger($this->arAccount, [], 10);

        $this->assertEquals(25, $page1['paginator']->total());
        $this->assertEquals(3, $page1['paginator']->lastPage());
        $this->assertCount(10, $page1['paginator']->items());
        $this->assertCount(5, $page3['paginator']->items());
        // Ending balance is a property of the whole filtered range, not the viewed page.
        $this->assertEquals($page1['ending_balance'], $page3['ending_balance']);
        $this->assertEquals(25000, $page1['ending_balance']);
    }

    public function test_ledger_list_gives_every_account_a_row_even_with_zero_activity(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000);

        $rows = $this->generalLedgerService->listAccounts([]);
        $codes = collect($rows)->pluck('account.code')->all();

        $this->assertContains('1200', $codes); // touched
        $this->assertContains('5100', $codes); // never touched — still present, zeroed

        $untouched = collect($rows)->firstWhere('account.code', '5100');
        $this->assertEquals(0.0, $untouched['opening_balance']);
        $this->assertEquals(0.0, $untouched['debit']);
        $this->assertEquals(0.0, $untouched['credit']);
        $this->assertEquals(0.0, $untouched['ending_balance']);
    }

    public function test_ledger_list_sums_to_zero_across_the_whole_chart_of_accounts(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000, taxAmount: 5000);
        $this->submittedInvoice(qty: 3, rate: 20000);

        $rows = $this->generalLedgerService->listAccounts([]);
        $net = collect($rows)->sum(function ($row) {
            $account = $row['account'];
            // Un-sign back to a plain debit-positive figure so unrelated account types can be summed together.
            return $account->isDebitNormal() ? $row['ending_balance'] : -$row['ending_balance'];
        });

        $this->assertEquals(0.0, round($net, 2));
    }

    public function test_inactive_account_still_shows_historical_activity(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000);
        $this->arAccount->update(['is_active' => false]);

        $result = $this->generalLedgerService->accountLedger($this->arAccount->fresh(), [], 15);

        $this->assertEquals(100000, $result['ending_balance']);
        $this->assertCount(1, $result['paginator']->items());
    }

    public function test_reference_type_filter_narrows_the_ledger(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000); // invoice-sourced
        $this->postManualJournalEntry(now()->toDateString(), 30000); // no reference_type

        $invoiceOnly = $this->generalLedgerService->accountLedger($this->arAccount, ['reference_type' => 'invoice'], 15);
        $this->assertCount(1, $invoiceOnly['paginator']->items());
        $this->assertEquals(100000, $invoiceOnly['ending_balance']);
    }

    public function test_branch_and_company_filters_return_empty_since_no_module_populates_branch_id_yet(): void
    {
        $this->submittedInvoice(qty: 5, rate: 20000);

        $branch = Branch::query()->first();
        $result = $this->generalLedgerService->accountLedger($this->arAccount, ['branch_id' => $branch->id], 15);

        // Proves the filter is real (narrows results), not decorative — even though nothing
        // currently populates journal_entry_lines.branch_id (docs/GENERAL_LEDGER_DESIGN.md §0/§4).
        $this->assertCount(0, $result['paginator']->items());
        $this->assertEquals(0.0, $result['ending_balance']);
    }

    public function test_reference_number_filter_matches_the_source_documents_number_not_the_journal_entrys_own(): void
    {
        $invoice = $this->submittedInvoice(qty: 5, rate: 20000);

        $matching = $this->generalLedgerService->accountLedger($this->arAccount, ['reference_number' => $invoice->document_number], 15);
        $this->assertCount(1, $matching['paginator']->items());

        $nonMatching = $this->generalLedgerService->accountLedger($this->arAccount, ['reference_number' => 'JE-00001'], 15);
        $this->assertCount(0, $nonMatching['paginator']->items());
    }
}
