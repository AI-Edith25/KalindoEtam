<?php

namespace Tests\Feature;

use App\Enums\AccountsPayableStatus;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AccountsPayable;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\PaymentEntry;
use App\Models\PaymentEntryAllocation;
use App\Models\PaymentEntryExpenseLine;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\PaymentEntryAllocationService;
use App\Services\PaymentEntryService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * payment_type=mixed — one voucher allocating its total across supplier-bill lines (possibly
 * spanning several suppliers) and general-expense lines together. Setup mirrors
 * PaymentEntryAllocationTest exactly (same GR->PI->AP helper chain), since a mixed voucher's
 * supplier lines settle through the exact same AccountsPayable machinery.
 */
class PaymentEntryMixedTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected PurchaseInvoiceService $purchaseInvoiceService;
    protected PaymentEntryService $paymentEntryService;
    protected PaymentEntryAllocationService $paymentEntryAllocationService;
    protected Warehouse $warehouse;
    protected \App\Models\Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);
        $this->purchaseInvoiceService = app(PurchaseInvoiceService::class);
        $this->paymentEntryService = app(PaymentEntryService::class);
        $this->paymentEntryAllocationService = app(PaymentEntryAllocationService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = \App\Models\Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
    }

    protected function submittedGoodsReceiptForSupplier(Supplier $supplier, int $qty, float $rate): GoodsReceipt
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $supplier->id,
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
        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->purchaseInvoiceService->submit($purchaseInvoice);

        return $goodsReceipt;
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    protected function draftMixedVoucher(float $amount): PaymentEntry
    {
        return $this->paymentEntryService->create([
            'payment_type' => 'mixed',
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'amount' => $amount,
        ]);
    }

    /**
     * The core scenario the whole feature exists for: two supplier bills across two DIFFERENT
     * suppliers plus one general-expense line, paid from one voucher — exactly one credit line
     * to cash for the combined total, and each bill's own outstanding balance drops correctly.
     */
    public function test_submit_mixed_settles_two_suppliers_and_one_expense_line_with_one_cash_credit(): void
    {
        $supplierA = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Supplier A']);
        $supplierB = Supplier::query()->create(['supplier_code' => 'S002', 'supplier_name' => 'Supplier B']);

        $grA = $this->submittedGoodsReceiptForSupplier($supplierA, qty: 5, rate: 20000); // 100000 outstanding
        $grB = $this->submittedGoodsReceiptForSupplier($supplierB, qty: 2, rate: 20000); // 40000 outstanding
        $apA = AccountsPayable::query()->where('goods_receipt_id', $grA->id)->firstOrFail();
        $apB = AccountsPayable::query()->where('goods_receipt_id', $grB->id)->firstOrFail();

        $voucher = $this->draftMixedVoucher(180000); // 100000 + 40000 + 40000 expense

        $voucher = $this->paymentEntryService->submit($voucher, [
            ['type' => 'supplier', 'accounts_payable_id' => $apA->id, 'amount' => 100000],
            ['type' => 'supplier', 'accounts_payable_id' => $apB->id, 'amount' => 40000],
            ['type' => 'expense', 'expense_account_id' => $this->accountId('6300'), 'description' => 'ATK bulanan', 'amount' => 40000],
        ]);

        $this->assertEquals(100000, (float) $apA->fresh()->paid_amount);
        $this->assertEquals(AccountsPayableStatus::PAID, $apA->fresh()->status);
        $this->assertEquals(40000, (float) $apB->fresh()->paid_amount);
        $this->assertEquals(AccountsPayableStatus::PAID, $apB->fresh()->status);
        $this->assertEquals(180000, (float) $voucher->fresh()->allocated_amount);
        $this->assertCount(2, PaymentEntryAllocation::all());
        $this->assertCount(1, PaymentEntryExpenseLine::all());

        // One aggregate journal for the voucher itself, with exactly one credit line to cash.
        $aggregateJournal = JournalEntry::query()->where('reference_type', 'payment_entry')->where('reference_id', $voucher->id)->firstOrFail();
        $lines = $aggregateJournal->lines()->with('chartOfAccount')->get();
        $creditLines = $lines->where('credit', '>', 0);
        $this->assertCount(1, $creditLines);
        $this->assertEquals(180000, (float) $creditLines->first()->credit);
        $this->assertEquals('1100', $creditLines->first()->chartOfAccount->code);
        $this->assertEquals(180000, (float) $lines->where('debit', '>', 0)->sum('debit'));
        $this->assertEquals(140000, (float) $lines->where('chartOfAccount.code', '1250')->sum('debit')); // 100000 + 40000 supplier legs
        $this->assertEquals(40000, (float) $lines->where('chartOfAccount.code', '6300')->sum('debit'));

        // Plus the existing per-allocation Dr 2000/Cr 1250 journal for each supplier line (unchanged mechanism).
        $this->assertDatabaseCount('journal_entries', 2 + 1 + 2); // PI(A) + PI(B) + aggregate + 2 per-allocation
    }

    public function test_submit_mixed_allows_a_shortfall_as_unallocated(): void
    {
        $supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Supplier A']);
        $gr = $this->submittedGoodsReceiptForSupplier($supplier, qty: 5, rate: 20000); // 100000 outstanding
        $ap = AccountsPayable::query()->where('goods_receipt_id', $gr->id)->firstOrFail();

        $voucher = $this->draftMixedVoucher(150000);
        $voucher = $this->paymentEntryService->submit($voucher, [
            ['type' => 'supplier', 'accounts_payable_id' => $ap->id, 'amount' => 100000],
        ]);

        $this->assertEquals(100000, (float) $voucher->fresh()->allocated_amount);
        $this->assertEquals(50000, $voucher->fresh()->unallocatedAmount());
    }

    public function test_submit_mixed_rejects_lines_exceeding_amount_paid(): void
    {
        $supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Supplier A']);
        $gr = $this->submittedGoodsReceiptForSupplier($supplier, qty: 5, rate: 20000);
        $ap = AccountsPayable::query()->where('goods_receipt_id', $gr->id)->firstOrFail();

        $voucher = $this->draftMixedVoucher(50000);

        try {
            $this->paymentEntryService->submit($voucher, [
                ['type' => 'supplier', 'accounts_payable_id' => $ap->id, 'amount' => 60000],
            ]);
            $this->fail('Expected lines summing above Amount Paid to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
        $this->assertEquals('draft', $voucher->fresh()->status->value);
    }

    public function test_submit_mixed_rejects_the_same_bill_twice(): void
    {
        $supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Supplier A']);
        $gr = $this->submittedGoodsReceiptForSupplier($supplier, qty: 10, rate: 20000); // 200000 outstanding
        $ap = AccountsPayable::query()->where('goods_receipt_id', $gr->id)->firstOrFail();

        $voucher = $this->draftMixedVoucher(200000);

        try {
            $this->paymentEntryService->submit($voucher, [
                ['type' => 'supplier', 'accounts_payable_id' => $ap->id, 'amount' => 100000],
                ['type' => 'supplier', 'accounts_payable_id' => $ap->id, 'amount' => 100000],
            ]);
            $this->fail('Expected the same Accounts Payable twice in one voucher to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
    }

    public function test_submit_mixed_rejects_over_allocating_a_single_bill(): void
    {
        $supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Supplier A']);
        $gr = $this->submittedGoodsReceiptForSupplier($supplier, qty: 2, rate: 20000); // 40000 outstanding
        $ap = AccountsPayable::query()->where('goods_receipt_id', $gr->id)->firstOrFail();

        $voucher = $this->draftMixedVoucher(100000);

        try {
            $this->paymentEntryService->submit($voucher, [
                ['type' => 'supplier', 'accounts_payable_id' => $ap->id, 'amount' => 60000],
            ]);
            $this->fail('Expected exceeding one bill\'s own outstanding to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
        $this->assertEquals(0, (float) $ap->fresh()->paid_amount);
    }

    /**
     * Reversing one supplier line's allocation (the existing, unmodified
     * PaymentEntryAllocationController::reverse() path) must restore only that bill and that
     * line's own per-allocation journal, leaving the aggregate journal, the other supplier
     * line, and the expense line completely untouched.
     */
    public function test_reversing_one_line_of_a_mixed_voucher_leaves_the_rest_untouched(): void
    {
        $supplierA = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Supplier A']);
        $supplierB = Supplier::query()->create(['supplier_code' => 'S002', 'supplier_name' => 'Supplier B']);
        $grA = $this->submittedGoodsReceiptForSupplier($supplierA, qty: 5, rate: 20000);
        $grB = $this->submittedGoodsReceiptForSupplier($supplierB, qty: 2, rate: 20000);
        $apA = AccountsPayable::query()->where('goods_receipt_id', $grA->id)->firstOrFail();
        $apB = AccountsPayable::query()->where('goods_receipt_id', $grB->id)->firstOrFail();

        $voucher = $this->draftMixedVoucher(180000);
        $voucher = $this->paymentEntryService->submit($voucher, [
            ['type' => 'supplier', 'accounts_payable_id' => $apA->id, 'amount' => 100000],
            ['type' => 'supplier', 'accounts_payable_id' => $apB->id, 'amount' => 40000],
            ['type' => 'expense', 'expense_account_id' => $this->accountId('6100'), 'description' => 'Transport', 'amount' => 40000],
        ]);

        $allocationA = PaymentEntryAllocation::query()->where('accounts_payable_id', $apA->id)->firstOrFail();
        $this->paymentEntryAllocationService->reverse($allocationA);

        $this->assertEquals(0, (float) $apA->fresh()->paid_amount);
        $this->assertEquals(AccountsPayableStatus::UNPAID, $apA->fresh()->status);
        // B and the expense line are untouched.
        $this->assertEquals(40000, (float) $apB->fresh()->paid_amount);
        $this->assertCount(1, PaymentEntryExpenseLine::all());

        $aggregateJournal = JournalEntry::query()->where('reference_type', 'payment_entry')->where('reference_id', $voucher->id)->firstOrFail();
        $this->assertNull($aggregateJournal->reversed_by_id, 'Reversing one line must not touch the voucher\'s own aggregate journal.');
    }
}
