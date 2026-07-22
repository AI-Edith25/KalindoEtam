<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
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
use App\Services\AccountsReceivableService;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\JournalEntryService;
use App\Services\ReceiptEntryService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected ReceiptEntryService $receiptEntryService;
    protected AccountsReceivableService $accountsReceivableService;
    protected JournalEntryService $journalEntryService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->receiptEntryService = app(ReceiptEntryService::class);
        $this->accountsReceivableService = app(AccountsReceivableService::class);
        $this->journalEntryService = app(JournalEntryService::class);

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
            qtyChange: 100,
            postingDatetime: now(),
        );
    }

    protected function submittedDelivery(int $qty = 10, float $rate = 10000): \App\Models\Delivery
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

        return $this->deliveryService->submit($delivery);
    }

    protected function submittedInvoice(int $qty = 10, float $rate = 10000, float $taxAmount = 0): Invoice
    {
        $delivery = $this->submittedDelivery($qty, $rate);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => $taxAmount,
        ]);

        return $this->invoiceService->submit($invoice);
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_submitting_an_invoice_posts_a_balanced_journal_entry(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 10000, taxAmount: 11000);

        $journalEntry = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->firstOrFail();

        $this->assertEquals(DocumentStatus::SUBMITTED, $journalEntry->status);
        $this->assertEquals(111000, (float) $journalEntry->total_debit);
        $this->assertEquals(111000, (float) $journalEntry->total_credit);

        // Regression check: morphTo('referenceDocument', ...) must name itself after the
        // method it's defined on, or eager loading silently populates the wrong relation key
        // and $journalEntry->referenceDocument resolves to null despite a correct query.
        $this->assertTrue($journalEntry->load('referenceDocument')->relationLoaded('referenceDocument'));
        $this->assertSame($invoice->document_number, $journalEntry->referenceDocument->document_number);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(111000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->debit);
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '4000')->credit);
        $this->assertEquals(11000, (float) $lines->firstWhere('chartOfAccount.code', '2100')->credit);
    }

    public function test_submitting_a_receipt_entry_posts_a_balanced_journal_entry(): void
    {
        $receiptEntry = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::CASH,
            'total_amount' => 100000,
        ]);
        $receiptEntry = $this->receiptEntryService->submit($receiptEntry);

        $journalEntry = JournalEntry::query()->where('reference_type', 'receipt_entry')->where('reference_id', $receiptEntry->id)->firstOrFail();

        $this->assertEquals(100000, (float) $journalEntry->total_debit);
        $this->assertEquals(100000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '1100')->debit);
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '1150')->credit);
    }

    public function test_an_unbalanced_manual_journal_entry_is_rejected(): void
    {
        $this->expectException(BusinessException::class);

        $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'description' => 'Unbalanced test entry',
            'lines' => [
                ['chart_of_account_id' => $this->accountId('1100'), 'debit' => 100],
                ['chart_of_account_id' => $this->accountId('1200'), 'credit' => 50],
            ],
        ]);

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_reversing_a_posted_journal_entry_creates_a_swapped_posted_entry(): void
    {
        $journalEntry = $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'description' => 'Manual adjusting entry',
            'lines' => [
                ['chart_of_account_id' => $this->accountId('1100'), 'debit' => 50000],
                ['chart_of_account_id' => $this->accountId('4100'), 'credit' => 50000],
            ],
        ]);
        $this->approveDocument($journalEntry);
        $journalEntry = $this->journalEntryService->post($journalEntry);

        $reversal = $this->journalEntryService->reverse($journalEntry);

        $this->assertEquals(DocumentStatus::SUBMITTED, $reversal->status);
        $this->assertSame($journalEntry->id, $reversal->reverses_id);
        $this->assertSame($reversal->id, $journalEntry->fresh()->reversed_by_id);

        $reversalLines = $reversal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(50000, (float) $reversalLines->firstWhere('chartOfAccount.code', '1100')->credit);
        $this->assertEquals(50000, (float) $reversalLines->firstWhere('chartOfAccount.code', '4100')->debit);
    }

    public function test_editing_and_deleting_a_posted_journal_entry_is_blocked(): void
    {
        $journalEntry = $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'lines' => [
                ['chart_of_account_id' => $this->accountId('1100'), 'debit' => 1000],
                ['chart_of_account_id' => $this->accountId('4100'), 'credit' => 1000],
            ],
        ]);
        $this->approveDocument($journalEntry);
        $journalEntry = $this->journalEntryService->post($journalEntry);

        try {
            $this->journalEntryService->update($journalEntry, ['description' => 'changed']);
            $this->fail('Expected updating a posted Journal Entry to throw.');
        } catch (BusinessException) {
        }

        try {
            $this->journalEntryService->delete($journalEntry);
            $this->fail('Expected deleting a posted Journal Entry to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseHas('journal_entries', ['id' => $journalEntry->id, 'deleted_at' => null]);
    }

    public function test_invoice_submission_rolls_back_completely_if_journal_posting_fails(): void
    {
        ChartOfAccount::query()->where('code', '1200')->update(['is_active' => false]);

        $delivery = $this->submittedDelivery(qty: 3, rate: 15000);
        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        try {
            $this->invoiceService->submit($invoice);
            $this->fail('Expected submit() to throw when the required chart of account is inactive.');
        } catch (BusinessException) {
        }

        $this->assertEquals(DocumentStatus::DRAFT, $invoice->fresh()->status);
        $this->assertDatabaseCount('accounts_receivables', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_receipt_entry_submission_rolls_back_completely_if_journal_posting_fails(): void
    {
        ChartOfAccount::query()->where('code', '1100')->update(['is_active' => false]);

        $receiptEntry = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::CASH,
            'total_amount' => 50000,
        ]);

        try {
            $this->receiptEntryService->submit($receiptEntry);
            $this->fail('Expected submit() to throw when the required chart of account is inactive.');
        } catch (BusinessException) {
        }

        $this->assertEquals(DocumentStatus::DRAFT, $receiptEntry->fresh()->status);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_cancelling_an_invoice_does_not_reverse_its_posted_journal_entry(): void
    {
        $invoice = $this->submittedInvoice(qty: 1, rate: 10000);
        $journalEntry = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->firstOrFail();

        $this->invoiceService->cancel($invoice);

        $journalEntry->refresh();
        $this->assertEquals(DocumentStatus::SUBMITTED, $journalEntry->status);
        $this->assertNull($journalEntry->reversed_by_id);
    }
}
