<?php

namespace Tests\Feature;

use App\Enums\AccountsPayableStatus;
use App\Enums\DocumentStatus;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AccountsPayable;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FifoLayer;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\TermsOfPayment;
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

class PurchaseInvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected PurchaseInvoiceService $purchaseInvoiceService;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);
        $this->purchaseInvoiceService = app(PurchaseInvoiceService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
    }

    protected function submittedGoodsReceipt(int $qty, float $rate, ?Supplier $supplier = null): GoodsReceipt
    {
        $supplier ??= $this->supplier;

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

        return $this->goodsReceiptService->submit($goodsReceipt);
    }

    public function test_purchase_invoice_created_from_goods_receipt_and_submit_creates_accounts_payable_and_posts_journal(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000); // 100000

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => 10000,
        ]);

        $this->assertEquals(DocumentStatus::DRAFT, $purchaseInvoice->status);
        $this->assertEquals(100000, (float) $purchaseInvoice->subtotal);
        $this->assertEquals(110000, (float) $purchaseInvoice->grand_total);
        $this->assertCount(1, $purchaseInvoice->items);

        $purchaseInvoice = $this->purchaseInvoiceService->submit($purchaseInvoice);

        $this->assertEquals(DocumentStatus::SUBMITTED, $purchaseInvoice->status);

        $accountsPayable = $purchaseInvoice->accountsPayable()->firstOrFail();
        $this->assertEquals(110000, (float) $accountsPayable->amount);
        $this->assertEquals(AccountsPayableStatus::UNPAID, $accountsPayable->status);
        $this->assertEquals($goodsReceipt->id, $accountsPayable->goods_receipt_id);

        $journalEntry = JournalEntry::query()->where('reference_type', 'purchase_invoice')->where('reference_id', $purchaseInvoice->id)->firstOrFail();
        $this->assertEquals(110000, (float) $journalEntry->total_debit);
        $this->assertEquals(110000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '5100')->debit);
        $this->assertEquals(10000, (float) $lines->firstWhere('chartOfAccount.code', '2100')->debit);
        $this->assertEquals(110000, (float) $lines->firstWhere('chartOfAccount.code', '2000')->credit);
    }

    public function test_multiple_goods_receipts_from_same_supplier_can_be_combined_into_one_invoice(): void
    {
        $first = $this->submittedGoodsReceipt(qty: 2, rate: 20000); // 40000
        $second = $this->submittedGoodsReceipt(qty: 3, rate: 20000); // 60000

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$first->id, $second->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertEquals(100000, (float) $purchaseInvoice->subtotal);
        $this->assertCount(2, $purchaseInvoice->items);
        $this->assertCount(2, $purchaseInvoice->goodsReceipts);
    }

    public function test_mixed_supplier_goods_receipts_cannot_be_combined(): void
    {
        $first = $this->submittedGoodsReceipt(qty: 2, rate: 20000);
        $otherSupplier = Supplier::query()->create(['supplier_code' => 'S002', 'supplier_name' => 'Other Supplier']);
        $second = $this->submittedGoodsReceipt(qty: 3, rate: 20000, supplier: $otherSupplier);

        $this->expectException(BusinessException::class);

        $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$first->id, $second->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_already_invoiced_goods_receipt_cannot_be_invoiced_again(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 2, rate: 20000);

        $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->expectException(BusinessException::class);

        $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_non_submitted_goods_receipt_cannot_be_invoiced(): void
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 2, 'rate' => 20000]],
        ]);
        $this->approveDocument($purchaseOrder);
        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder);

        $draftGoodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 2]],
        ]);

        $this->expectException(BusinessException::class);

        $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$draftGoodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_cancel_deletes_accounts_payable_when_unpaid(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 2, rate: 20000);

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $purchaseInvoice = $this->purchaseInvoiceService->submit($purchaseInvoice);
        $accountsPayableId = $purchaseInvoice->accountsPayable()->firstOrFail()->id;

        $this->purchaseInvoiceService->cancel($purchaseInvoice);

        $this->assertEquals(DocumentStatus::CANCELLED, $purchaseInvoice->fresh()->status);
        $this->assertSoftDeleted('accounts_payables', ['id' => $accountsPayableId]);
    }

    public function test_cancel_blocked_once_payment_applied(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 2, rate: 20000); // 40000

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $purchaseInvoice = $this->purchaseInvoiceService->submit($purchaseInvoice);

        $accountsPayable = $purchaseInvoice->accountsPayable()->firstOrFail();
        app(\App\Services\AccountsPayableService::class)->settle($accountsPayable, 40000);

        $this->expectException(BusinessException::class);

        $this->purchaseInvoiceService->cancel($purchaseInvoice->fresh());
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_direct_invoice_creates_and_submits_without_stock_or_goods_receipt(): void
    {
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'source' => 'direct',
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'attention' => 'B 9211 PFU',
            'department' => 'WORKSHOP',
            'items' => [
                ['chart_of_account_id' => $this->accountId('6100'), 'description' => 'BAN DALAM+PASANG 10.20', 'qty' => 1, 'rate' => 350000],
            ],
        ]);

        $this->assertEquals('direct', $purchaseInvoice->source->value);
        $this->assertNull($purchaseInvoice->goods_receipt_id);
        $this->assertNull($purchaseInvoice->purchase_order_id);
        $this->assertEquals(350000, (float) $purchaseInvoice->subtotal);
        $this->assertEquals('B 9211 PFU', $purchaseInvoice->attention);
        $this->assertEquals('WORKSHOP', $purchaseInvoice->department);

        $purchaseInvoice = $this->purchaseInvoiceService->submit($purchaseInvoice);

        $this->assertEquals(DocumentStatus::SUBMITTED, $purchaseInvoice->status);
        $accountsPayable = $purchaseInvoice->accountsPayable()->firstOrFail();
        $this->assertEquals(350000, (float) $accountsPayable->amount);
        $this->assertNull($accountsPayable->goods_receipt_id);
        $this->assertNull($accountsPayable->purchase_order_id);

        $this->assertDatabaseCount('stock_ledgers', 0);
        $this->assertDatabaseCount('fifo_layers', 0);
    }

    public function test_direct_invoice_journal_posts_grouped_by_expense_account_plus_tax(): void
    {
        $tax = \App\Models\Tax::query()->create([
            'code' => 'PPN11', 'name' => 'PPN 11%', 'type' => \App\Enums\TaxType::VAT,
            'transaction_type' => \App\Enums\TaxTransactionType::PURCHASE, 'rate' => 11, 'is_active' => true,
        ]);

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'source' => 'direct',
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['chart_of_account_id' => $this->accountId('6100'), 'description' => 'Transport A', 'qty' => 1, 'rate' => 100000],
                ['chart_of_account_id' => $this->accountId('6100'), 'description' => 'Transport B', 'qty' => 1, 'rate' => 50000],
                ['chart_of_account_id' => $this->accountId('6200'), 'description' => 'Konsumsi', 'qty' => 1, 'rate' => 200000, 'tax_id' => $tax->id],
            ],
        ]);

        // 2 lines on 6100 combine into one debit; tax only on the 6200 line (11% of 200000 = 22000).
        $this->assertEquals(350000, (float) $purchaseInvoice->subtotal);
        $this->assertEquals(22000, (float) $purchaseInvoice->tax_amount);
        $this->assertEquals(372000, (float) $purchaseInvoice->grand_total);

        $purchaseInvoice = $this->purchaseInvoiceService->submit($purchaseInvoice);

        $journalEntry = JournalEntry::query()->where('reference_type', 'purchase_invoice')->where('reference_id', $purchaseInvoice->id)->firstOrFail();
        $this->assertEquals(372000, (float) $journalEntry->total_debit);
        $this->assertEquals(372000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(150000, (float) $lines->firstWhere('chartOfAccount.code', '6100')->debit);
        $this->assertEquals(200000, (float) $lines->firstWhere('chartOfAccount.code', '6200')->debit);
        $this->assertEquals(22000, (float) $lines->firstWhere('chartOfAccount.code', '2100')->debit);
        $this->assertEquals(372000, (float) $lines->firstWhere('chartOfAccount.code', '2000')->credit);
    }

    public function test_direct_invoice_accounts_payable_can_be_settled_via_payment_entry(): void
    {
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'source' => 'direct',
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['chart_of_account_id' => $this->accountId('6100'), 'description' => 'Transport', 'qty' => 1, 'rate' => 480000],
            ],
        ]);
        $purchaseInvoice = $this->purchaseInvoiceService->submit($purchaseInvoice);
        $accountsPayable = $purchaseInvoice->accountsPayable()->firstOrFail();

        $paymentEntryService = app(PaymentEntryService::class);
        $payment = $paymentEntryService->create([
            'payment_type' => 'supplier',
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'amount' => 480000,
        ]);
        $payment = $paymentEntryService->submit($payment);

        app(PaymentEntryAllocationService::class)->allocateBatch($payment, [
            ['accounts_payable_id' => $accountsPayable->id, 'amount' => 480000],
        ]);

        $this->assertEquals(480000, (float) $accountsPayable->fresh()->paid_amount);
        $this->assertEquals(AccountsPayableStatus::PAID, $accountsPayable->fresh()->status);
    }

    public function test_direct_invoice_due_date_defaults_from_supplier_terms_of_payment(): void
    {
        $top = TermsOfPayment::query()->create(['code' => 'N30', 'name' => 'Net 30', 'days' => 30]);
        $this->supplier->update(['terms_of_payment_id' => $top->id]);

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'source' => 'direct',
            'supplier_id' => $this->supplier->id,
            'invoice_date' => '2026-09-01',
            'items' => [
                ['chart_of_account_id' => $this->accountId('6100'), 'description' => 'Transport', 'qty' => 1, 'rate' => 10000],
            ],
        ]);

        $this->assertSame('2026-10-01', $purchaseInvoice->due_date->toDateString());
    }

    public function test_direct_invoice_rejects_inactive_or_non_expense_account(): void
    {
        try {
            $this->purchaseInvoiceService->create([
                'source' => 'direct',
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [
                    ['chart_of_account_id' => $this->accountId('1200'), 'description' => 'Wrong account', 'qty' => 1, 'rate' => 10000],
                ],
            ]);
            $this->fail('Expected a non-Expense account to throw.');
        } catch (BusinessException) {
        }

        ChartOfAccount::query()->where('code', '6100')->update(['is_active' => false]);

        try {
            $this->purchaseInvoiceService->create([
                'source' => 'direct',
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [
                    ['chart_of_account_id' => $this->accountId('6100'), 'description' => 'Inactive account', 'qty' => 1, 'rate' => 10000],
                ],
            ]);
            $this->fail('Expected an inactive account to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('purchase_invoices', 0);
    }
}
