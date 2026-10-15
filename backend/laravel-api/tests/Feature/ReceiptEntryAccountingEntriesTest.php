<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\ReceiptEntryService;
use App\Services\SalesOrderService;
use App\Enums\WarehouseType;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ReceiptEntry::accountingEntries() — the real posted GL entries behind the detail page's
 * "Accounting Detail" section. Not a synthetic re-derivation: just the receipt's own submit
 * journal plus one per active (non-reversed) allocation, same accounts AccountingService
 * actually posted (see PaymentAllocationTest for the posting logic itself).
 */
class ReceiptEntryAccountingEntriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    public function test_a_submitted_unallocated_receipt_shows_only_its_own_journal(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $cashAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();

        $receiptEntryService = app(ReceiptEntryService::class);
        $payment = $receiptEntryService->create([
            'customer_id' => $customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $cashAccount->id,
            'total_amount' => 100000,
        ]);
        $payment = $receiptEntryService->submit($payment);

        $entries = $payment->load('items.journalEntries.lines', 'journalEntries.lines')->accountingEntries();

        $this->assertCount(1, $entries);
        $lines = $entries->first()->lines;
        $this->assertEquals(100000, (float) $lines->firstWhere('chart_of_account_id', $cashAccount->id)->debit);
    }

    public function test_allocating_adds_the_allocation_journal_and_reversing_removes_it_again(): void
    {
        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id, 'standard_rate' => 20000,
        ]);
        $this->seedStock($item->id, $warehouse->id, 100);
        $cashAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();

        $salesOrderService = app(SalesOrderService::class);
        $deliveryService = app(DeliveryService::class);
        $invoiceService = app(InvoiceService::class);
        $receiptEntryService = app(ReceiptEntryService::class);
        $paymentAllocationService = app(PaymentAllocationService::class);

        $salesOrder = $salesOrderService->create(['customer_id' => $customer->id, 'order_date' => now()->toDateString(), 'items' => [['item_id' => $item->id, 'qty' => 5, 'rate' => 20000]]]);
        $this->approveDocument($salesOrder);
        $salesOrderService->approve($salesOrder);
        $delivery = $deliveryService->create([
            'sales_order_id' => $salesOrder->id, 'warehouse_id' => $warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);
        $delivery = $deliveryService->complete($delivery);
        $invoice = $invoiceService->create(['delivery_ids' => [$delivery->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);
        $invoice = $invoiceService->submit($invoice);
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();

        $payment = $receiptEntryService->create(['customer_id' => $customer->id, 'receipt_date' => now()->toDateString(), 'cash_account_id' => $cashAccount->id, 'total_amount' => 100000]);
        $payment = $receiptEntryService->submit($payment);

        $this->assertCount(1, $payment->fresh()->load('items.journalEntries.lines', 'journalEntries.lines')->accountingEntries());

        $allocation = $paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 100000],
        ])->first();

        $withAllocation = $payment->fresh()->load('items.journalEntries.lines', 'journalEntries.lines')->accountingEntries();
        $this->assertCount(2, $withAllocation);
        $arAccount = ChartOfAccount::query()->where('code', '112.01')->firstOrFail();
        $this->assertTrue($withAllocation->contains(fn ($entry) => $entry->lines->contains('chart_of_account_id', $arAccount->id)));

        $paymentAllocationService->reverse($allocation);

        $afterReverse = $payment->fresh()->load('items.journalEntries.lines', 'journalEntries.lines')->accountingEntries();
        $this->assertCount(1, $afterReverse);
    }
}
