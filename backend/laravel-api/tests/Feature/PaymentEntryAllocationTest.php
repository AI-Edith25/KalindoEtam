<?php

namespace Tests\Feature;

use App\Enums\AccountsPayableStatus;
use App\Enums\DocumentStatus;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AccountsPayable;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\PaymentEntry;
use App\Models\PaymentEntryAllocation;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\User;
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
 * AP mirror of PaymentAllocationTest — same coverage shape, retargeted at
 * PaymentEntry/AccountsPayable/PaymentEntryAllocation and the '1250'
 * Advance to Suppliers / '2000' Accounts Payable accounts.
 */
class PaymentEntryAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected PurchaseInvoiceService $purchaseInvoiceService;
    protected PaymentEntryService $paymentEntryService;
    protected PaymentEntryAllocationService $paymentEntryAllocationService;
    protected Supplier $supplier;
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
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = \App\Models\Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
    }

    /**
     * Goods Receipt submit is stock-only (Accounts Payable/GL moved to
     * Purchase Invoice — see PurchaseInvoiceService::submit()); this helper
     * carries every caller through the full GR -> Purchase Invoice -> AP
     * chain so `AccountsPayable::where('goods_receipt_id', ...)` (still
     * populated from the Invoice's anchor column) keeps resolving exactly
     * like it did when Goods Receipt created AP directly.
     */
    protected function submittedGoodsReceipt(int $qty, float $rate): GoodsReceipt
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
        return \App\Models\ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    /**
     * A payment that has been made but not yet allocated to anything.
     * Bypasses PaymentEntryService and drives Documentable directly, since
     * most tests here only care about PaymentEntryAllocationService's own
     * behavior against a payment's status/total_amount/allocated_amount —
     * see test_paying_then_allocating_a_payment_nets_the_suspense_account_to_zero()
     * for a test that goes through the real PaymentEntryService instead.
     */
    protected function submittedPayment(float $amount): PaymentEntry
    {
        $paymentEntry = PaymentEntry::query()->create([
            'payment_type' => 'supplier',
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'total_amount' => $amount,
            'allocated_amount' => 0,
        ]);

        return $paymentEntry->submit();
    }

    /**
     * The two-leg suspense-account model end to end, through the real
     * PaymentEntryService (pay) and PaymentEntryAllocationService
     * (allocate) — not the test's submittedPayment() bypass. Confirms the
     * '1250' Advance to Suppliers account nets to zero once a payment is
     * fully allocated: the payment's journal debits it, the allocation's
     * journal credits it by the same amount.
     */
    public function test_paying_then_allocating_a_payment_nets_the_suspense_account_to_zero(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();

        $payment = $this->paymentEntryService->create([
            'payment_type' => 'supplier',
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'amount' => 100000,
        ]);
        $payment = $this->paymentEntryService->submit($payment);

        $paymentJournal = JournalEntry::query()->where('reference_type', 'payment_entry')->where('reference_id', $payment->id)->firstOrFail();
        $paymentLines = $paymentJournal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $paymentLines->firstWhere('chartOfAccount.code', '1250')->debit);
        $this->assertEquals(100000, (float) $paymentLines->firstWhere('chartOfAccount.code', '1100')->credit);
        $this->assertEquals(0, $accountsPayable->fresh()->paid_amount); // not yet allocated

        $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 100000],
        ]);

        $this->assertEquals(100000, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(0, $payment->fresh()->unallocatedAmount());

        $suspenseAccountId = $this->accountId('1250');
        $netSuspense = \App\Models\JournalEntryLine::query()->where('chart_of_account_id', $suspenseAccountId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertEquals(0, (float) $netSuspense);
    }

    public function test_allocate_batch_creates_allocation_settles_ap_and_posts_balanced_journal(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(100000);

        $allocations = $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 100000],
        ]);

        $this->assertCount(1, $allocations);
        $allocation = $allocations->first();
        $this->assertEquals(100000, (float) $allocation->allocated_amount);
        $this->assertFalse($allocation->is_reversed);

        $this->assertEquals(100000, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(AccountsPayableStatus::PAID, $accountsPayable->fresh()->status);

        $freshPayment = $payment->fresh();
        $this->assertEquals(100000, (float) $freshPayment->allocated_amount);
        $this->assertEquals(0, $freshPayment->unallocatedAmount());

        $journalEntry = JournalEntry::query()->where('reference_type', 'payment_entry_allocation')->where('reference_id', $allocation->id)->firstOrFail();
        $this->assertEquals(DocumentStatus::SUBMITTED, $journalEntry->status);
        $this->assertEquals(100000, (float) $journalEntry->total_debit);
        $this->assertEquals(100000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '2000')->debit);
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '1250')->credit);
    }

    /** Partial allocation: only part of the payment is applied now, the rest stays available for a later allocateBatch() call. */
    public function test_allocate_batch_supports_partial_allocation_leaving_a_balance_for_later(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 10, rate: 20000); // 200000 outstanding
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(100000);

        $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 60000],
        ]);

        $this->assertEquals(60000, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(AccountsPayableStatus::PARTIALLY_PAID, $accountsPayable->fresh()->status);
        $this->assertEquals(60000, (float) $payment->fresh()->allocated_amount);
        $this->assertEquals(40000, $payment->fresh()->unallocatedAmount());

        // The remaining 40000 is still allocatable, against the same or a different payable.
        $this->paymentEntryAllocationService->allocateBatch($payment->fresh(), [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 40000],
        ]);

        $this->assertEquals(100000, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(100000, (float) $payment->fresh()->allocated_amount);
        $this->assertEquals(0, $payment->fresh()->unallocatedAmount());
        $this->assertCount(2, PaymentEntryAllocation::all());
    }

    public function test_allocate_batch_splits_one_payment_across_multiple_payables(): void
    {
        $goodsReceipt1 = $this->submittedGoodsReceipt(qty: 2, rate: 20000);
        $goodsReceipt2 = $this->submittedGoodsReceipt(qty: 3, rate: 20000);
        $ap1 = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt1->id)->firstOrFail();
        $ap2 = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt2->id)->firstOrFail();
        $payment = $this->submittedPayment(100000);

        $allocations = $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $ap1->id, 'amount' => 40000],
            ['accounts_payable_id' => $ap2->id, 'amount' => 60000],
        ]);

        $this->assertCount(2, $allocations);
        $this->assertEquals(40000, (float) $ap1->fresh()->paid_amount);
        $this->assertEquals(60000, (float) $ap2->fresh()->paid_amount);
        $this->assertEquals(100000, (float) $payment->fresh()->allocated_amount);
        $this->assertDatabaseCount('journal_entries', 4); // 2 purchase invoice journals + 2 allocation journals
    }

    public function test_allocate_batch_rejects_amount_exceeding_payment_unallocated_balance(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 10, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(50000);

        try {
            $this->paymentEntryAllocationService->allocateBatch($payment, [
                ['accounts_payable_id' => $accountsPayable->id, 'amount' => 60000],
            ]);
            $this->fail('Expected allocating more than the payment\'s unallocated balance to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
        $this->assertEquals(0, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);
    }

    public function test_allocate_batch_rejects_amount_exceeding_payable_outstanding(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 2, rate: 20000); // 40000 outstanding
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(100000);

        try {
            $this->paymentEntryAllocationService->allocateBatch($payment, [
                ['accounts_payable_id' => $accountsPayable->id, 'amount' => 60000],
            ]);
            $this->fail('Expected allocating more than the payable\'s outstanding to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);
    }

    public function test_allocate_batch_rejects_duplicate_payable_in_same_batch(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 10, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(200000);

        try {
            $this->paymentEntryAllocationService->allocateBatch($payment, [
                ['accounts_payable_id' => $accountsPayable->id, 'amount' => 50000],
                ['accounts_payable_id' => $accountsPayable->id, 'amount' => 50000],
            ]);
            $this->fail('Expected duplicate Accounts Payable references in one batch to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
    }

    public function test_allocate_batch_rejects_a_draft_payment(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 1, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();

        $draftPayment = PaymentEntry::query()->create([
            'payment_type' => 'supplier',
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'total_amount' => 20000,
            'allocated_amount' => 0,
        ]);

        try {
            $this->paymentEntryAllocationService->allocateBatch($draftPayment, [
                ['accounts_payable_id' => $accountsPayable->id, 'amount' => 20000],
            ]);
            $this->fail('Expected allocating an unsubmitted payment to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
    }

    public function test_allocate_batch_rejects_a_different_suppliers_payable(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();

        $otherSupplier = Supplier::query()->create(['supplier_code' => 'S002', 'supplier_name' => 'Other Supplier']);
        $payment = PaymentEntry::query()->create([
            'payment_type' => 'supplier',
            'supplier_id' => $otherSupplier->id,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'total_amount' => 100000,
            'allocated_amount' => 0,
        ])->submit();

        try {
            $this->paymentEntryAllocationService->allocateBatch($payment, [
                ['accounts_payable_id' => $accountsPayable->id, 'amount' => 100000],
            ]);
            $this->fail('Expected allocating against a different supplier\'s payable to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
    }

    public function test_reverse_restores_payable_and_payment_balance_and_posts_swapped_journal(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(100000);

        $allocation = $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 100000],
        ])->first();

        $originalJournal = JournalEntry::query()->where('reference_type', 'payment_entry_allocation')->where('reference_id', $allocation->id)->firstOrFail();

        $reversed = $this->paymentEntryAllocationService->reverse($allocation);

        $this->assertTrue($reversed->is_reversed);
        $this->assertEquals(0, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(AccountsPayableStatus::UNPAID, $accountsPayable->fresh()->status);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);

        $originalJournal->refresh();
        $this->assertEquals(DocumentStatus::SUBMITTED, $originalJournal->status);
        $this->assertNotNull($originalJournal->reversed_by_id);

        $reversalJournal = JournalEntry::query()->findOrFail($originalJournal->reversed_by_id);
        $reversalLines = $reversalJournal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $reversalLines->firstWhere('chartOfAccount.code', '2000')->credit);
        $this->assertEquals(100000, (float) $reversalLines->firstWhere('chartOfAccount.code', '1250')->debit);
    }

    public function test_reverse_twice_throws(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 1, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(20000);

        $allocation = $this->paymentEntryAllocationService->allocateBatch($payment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 20000],
        ])->first();

        $this->paymentEntryAllocationService->reverse($allocation);

        try {
            $this->paymentEntryAllocationService->reverse($allocation->fresh());
            $this->fail('Expected reversing an already-reversed allocation to throw.');
        } catch (BusinessException) {
        }

        // The failed second reverse must not have double-restored the balances.
        $this->assertEquals(0, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);
    }

    /** Double-submit: replaying the exact same allocation request (e.g. a double-click) must not double-apply it. */
    public function test_double_submitting_the_same_allocation_request_fails_on_the_second_call(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(100000);
        $lines = [['accounts_payable_id' => $accountsPayable->id, 'amount' => 100000]];

        $this->paymentEntryAllocationService->allocateBatch($payment, $lines);

        try {
            $this->paymentEntryAllocationService->allocateBatch($payment->fresh(), $lines);
            $this->fail('Expected replaying the same allocation request to throw.');
        } catch (BusinessException) {
        }

        $this->assertCount(1, PaymentEntryAllocation::all());
        $this->assertEquals(100000, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(100000, (float) $payment->fresh()->allocated_amount);
    }

    public function test_allocate_batch_rolls_back_completely_if_journal_posting_fails(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $payment = $this->submittedPayment(100000);

        \App\Models\ChartOfAccount::query()->where('code', '1250')->update(['is_active' => false]);

        try {
            $this->paymentEntryAllocationService->allocateBatch($payment, [
                ['accounts_payable_id' => $accountsPayable->id, 'amount' => 100000],
            ]);
            $this->fail('Expected allocateBatch() to throw when the required chart of account is inactive.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('payment_entry_allocations', 0);
        $this->assertEquals(0, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(0, (float) $payment->fresh()->allocated_amount);
        // Only the Purchase Invoice's own journal from submittedGoodsReceipt() should exist — none for the failed allocation.
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_second_allocation_fails_once_payable_outstanding_is_exhausted(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 2, rate: 20000); // 40000 outstanding
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $paymentOne = $this->submittedPayment(40000);
        $paymentTwo = $this->submittedPayment(40000);

        $this->paymentEntryAllocationService->allocateBatch($paymentOne, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 40000],
        ]);

        try {
            $this->paymentEntryAllocationService->allocateBatch($paymentTwo, [
                ['accounts_payable_id' => $accountsPayable->id, 'amount' => 40000],
            ]);
            $this->fail('Expected the second allocation against an already-settled payable to throw.');
        } catch (BusinessException) {
        }

        $this->assertEquals(40000, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(0, (float) $paymentTwo->fresh()->allocated_amount);
        $this->assertCount(1, PaymentEntryAllocation::all());
    }

    /**
     * Payment Voucher's "Reference" must point at the Purchase Invoice, not the
     * Purchase Order that predates it — see AccountsPayableService::createFromInvoice().
     * Guards AccountsPayableResource against dropping invoice_id/reference_number
     * back to a PO-only shape.
     */
    public function test_accounts_payable_api_exposes_the_purchase_invoice_as_the_reference(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000);
        $accountsPayable = AccountsPayable::query()->where('goods_receipt_id', $goodsReceipt->id)->firstOrFail();
        $purchaseInvoice = \App\Models\PurchaseInvoice::query()->findOrFail($accountsPayable->invoice_id);

        Permission::query()->firstOrCreate(['name' => 'finance.accounts_payable.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('finance.accounts_payable.view');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/accounts-payables/{$accountsPayable->id}");

        $response->assertOk();
        $response->assertJsonPath('data.invoice_id', $purchaseInvoice->id);
        $response->assertJsonPath('data.reference_number', $purchaseInvoice->document_number);
    }
}
