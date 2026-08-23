<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\ItemGroup;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\AccountsPayableService;
use App\Services\GeneralLedgerService;
use App\Services\GoodsReceiptService;
use App\Services\PaymentEntryService;
use App\Services\PurchaseOrderService;
use App\Services\TrialBalanceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Database\Seeders\ReportAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Purchase -> Accounts Payable -> Outgoing Payment chain never posted to
 * the ledger before this sprint (confirmed by grepping every
 * AccountingService::postForDocument() caller — none were Purchase-side).
 * This is the first real test coverage of GoodsReceipt::journalLines() and
 * PaymentEntry::journalLines() (both branches: Supplier and General Expense).
 */
class PurchaseAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected PaymentEntryService $paymentEntryService;
    protected AccountsPayableService $accountsPayableService;
    protected TrialBalanceService $trialBalanceService;
    protected GeneralLedgerService $generalLedgerService;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected \App\Models\Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ReportAccountMappingSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);
        $this->paymentEntryService = app(PaymentEntryService::class);
        $this->accountsPayableService = app(AccountsPayableService::class);
        $this->trialBalanceService = app(TrialBalanceService::class);
        $this->generalLedgerService = app(GeneralLedgerService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = \App\Models\Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
    }

    protected function submittedGoodsReceipt(int $qty = 5, float $rate = 20000): GoodsReceipt
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($purchaseOrder);
        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => $qty]],
        ]);

        return $this->goodsReceiptService->submit($goodsReceipt);
    }

    public function test_goods_receipt_posts_purchase_expense_debit_and_accounts_payable_credit(): void
    {
        $this->submittedGoodsReceipt(qty: 5, rate: 20000); // 100000

        $result = $this->trialBalanceService->summarize([]);
        $purchaseExpense = collect($result['rows'])->firstWhere('account.code', '5100');
        $accountsPayable = collect($result['rows'])->firstWhere('account.code', '2000');

        $this->assertEquals(100000.0, $purchaseExpense['debit']);
        $this->assertEquals(100000.0, $accountsPayable['credit']);
    }

    public function test_supplier_payment_entry_posts_advance_to_suppliers_debit_and_cash_credit(): void
    {
        $this->submittedGoodsReceipt(qty: 5, rate: 20000); // 100000, unused here beyond seeding AP

        $paymentEntry = $this->paymentEntryService->create([
            'payment_type' => 'supplier',
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
            'amount' => 40000,
        ]);
        $this->paymentEntryService->submit($paymentEntry);

        $result = $this->trialBalanceService->summarize([]);
        $advanceToSuppliersRow = collect($result['rows'])->firstWhere('account.code', '1250');
        $cashRow = collect($result['rows'])->firstWhere('account.code', '1100');

        // Paying money out (not yet allocated to a bill) debits the suspense asset, not AP directly.
        $this->assertEquals(40000.0, $advanceToSuppliersRow['debit']);
        $this->assertEquals(40000.0, $cashRow['credit']);
    }

    public function test_allocating_a_supplier_payment_settles_accounts_payable_and_nets_the_suspense_account(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000); // 100000
        $accountsPayable = \App\Models\AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();

        $paymentEntry = $this->paymentEntryService->create([
            'payment_type' => 'supplier',
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
            'amount' => 40000,
        ]);
        $paymentEntry = $this->paymentEntryService->submit($paymentEntry);

        app(\App\Services\PaymentEntryAllocationService::class)->allocateBatch($paymentEntry, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 40000],
        ]);

        $result = $this->trialBalanceService->summarize([]);
        $accountsPayableRow = collect($result['rows'])->firstWhere('account.code', '2000');
        $advanceToSuppliersRow = collect($result['rows'])->firstWhere('account.code', '1250');

        // Net AP: 100000 credit (Goods Receipt) - 40000 debit (allocation) = 60000 credit remaining.
        $this->assertEqualsWithDelta(60000.0, $accountsPayableRow['credit'], 0.001);
        // 1250 nets to zero: 40000 debit (payment) - 40000 credit (allocation).
        $this->assertEqualsWithDelta(0.0, $advanceToSuppliersRow['debit'] - $advanceToSuppliersRow['credit'], 0.001);
    }

    public function test_general_expense_payment_entry_posts_expense_debit_and_cash_credit_not_accounts_payable(): void
    {
        $transportAccount = ChartOfAccount::query()->where('code', '6100')->firstOrFail();

        $paymentEntry = $this->paymentEntryService->create([
            'payment_type' => 'general_expense',
            'expense_account_id' => $transportAccount->id,
            'description' => 'Ojek online ke kantor pajak',
            'amount' => 75000,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
        ]);
        $this->paymentEntryService->submit($paymentEntry);

        $result = $this->trialBalanceService->summarize([]);
        $transportRow = collect($result['rows'])->firstWhere('account.code', '6100');
        $cashRow = collect($result['rows'])->firstWhere('account.code', '1100');
        $accountsPayableRow = collect($result['rows'])->firstWhere('account.code', '2000');

        $this->assertEquals(75000.0, $transportRow['debit']);
        $this->assertEquals(75000.0, $cashRow['credit']);
        $this->assertEquals(0.0, $accountsPayableRow['debit']);
        $this->assertEquals(0.0, $accountsPayableRow['credit']);
    }

    public function test_general_expense_payment_has_no_supplier_and_no_items(): void
    {
        $transportAccount = ChartOfAccount::query()->where('code', '6200')->firstOrFail();

        $paymentEntry = $this->paymentEntryService->create([
            'payment_type' => 'general_expense',
            'expense_account_id' => $transportAccount->id,
            'description' => 'Konsumsi rapat',
            'amount' => 50000,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
        ]);

        $this->assertNull($paymentEntry->supplier_id);
        $this->assertCount(0, $paymentEntry->items);
        $this->assertEquals(50000.0, (float) $paymentEntry->total_amount);
    }
}
