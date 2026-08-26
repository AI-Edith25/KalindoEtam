<?php

namespace Tests\Feature;

use App\Enums\AccountsPayableStatus;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseReturnReason;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseReturnService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected PurchaseInvoiceService $purchaseInvoiceService;
    protected PurchaseReturnService $purchaseReturnService;
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
        $this->purchaseReturnService = app(PurchaseReturnService::class);

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

    protected function submittedPurchaseInvoice(int $qty = 10, float $rate = 20000): PurchaseInvoice
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

        return $this->purchaseInvoiceService->submit($purchaseInvoice);
    }

    public function test_purchase_return_reduces_payable_posts_journal_and_moves_stock_out(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 10, rate: 20000); // grand_total 200000
        $invoiceItem = $purchaseInvoice->items->first();

        $stockBeforeReturn = StockLedger::query()->where('item_id', $this->item->id)->sum('qty_change');
        $this->assertEquals(10, $stockBeforeReturn);

        $purchaseReturn = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::DAMAGED_GOODS->value,
            'items' => [
                ['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 3, 'amount' => 60000],
            ],
        ]);

        $this->assertEquals(DocumentStatus::DRAFT, $purchaseReturn->status);
        $this->assertEquals(60000, (float) $purchaseReturn->total_amount);

        $purchaseReturn = $this->purchaseReturnService->submit($purchaseReturn);

        $this->assertEquals(DocumentStatus::SUBMITTED, $purchaseReturn->status);

        $accountsPayable = $purchaseInvoice->accountsPayable()->firstOrFail()->fresh();
        $this->assertEquals(140000, (float) $accountsPayable->amount); // 200000 - 60000
        $this->assertEquals(60000, (float) $accountsPayable->credited_amount);
        $this->assertEquals(AccountsPayableStatus::UNPAID, $accountsPayable->status);

        $journalEntry = JournalEntry::query()->where('reference_type', 'purchase_return')->where('reference_id', $purchaseReturn->id)->firstOrFail();
        $this->assertEquals(60000, (float) $journalEntry->total_debit);
        $this->assertEquals(60000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(60000, (float) $lines->firstWhere('chartOfAccount.code', '2000')->debit);
        $this->assertEquals(60000, (float) $lines->firstWhere('chartOfAccount.code', '5050')->credit);

        $stockAfterReturn = StockLedger::query()->where('item_id', $this->item->id)->sum('qty_change');
        $this->assertEquals(7, $stockAfterReturn); // 10 received - 3 returned
    }

    public function test_return_quantity_cannot_exceed_what_remains_returnable(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 4, rate: 20000); // grand_total 80000
        $invoiceItem = $purchaseInvoice->items->first();

        $first = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::QUANTITY_DISCREPANCY->value,
            'items' => [['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 4, 'amount' => 80000]],
        ]);
        $this->purchaseReturnService->submit($first);

        try {
            $this->purchaseReturnService->create([
                'purchase_invoice_id' => $purchaseInvoice->id,
                'return_date' => now()->toDateString(),
                'reason' => PurchaseReturnReason::QUANTITY_DISCREPANCY->value,
                'items' => [['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 1, 'amount' => 20000]],
            ]);
            $this->fail('Expected a Return exceeding the remaining returnable qty/amount to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('purchase_returns', 1);
    }

    public function test_reverse_restores_payable_journal_and_stock(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 5, rate: 20000); // grand_total 100000
        $invoiceItem = $purchaseInvoice->items->first();

        $purchaseReturn = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::WRONG_ITEM->value,
            'items' => [['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 2, 'amount' => 40000]],
        ]);
        $purchaseReturn = $this->purchaseReturnService->submit($purchaseReturn);

        $originalJournal = JournalEntry::query()->where('reference_type', 'purchase_return')->where('reference_id', $purchaseReturn->id)->firstOrFail();

        $reversed = $this->purchaseReturnService->reverse($purchaseReturn);

        $this->assertTrue($reversed->is_reversed);
        $this->assertNotNull($reversed->reversed_at);

        $accountsPayable = $purchaseInvoice->accountsPayable()->firstOrFail()->fresh();
        $this->assertEquals(100000, (float) $accountsPayable->amount);
        $this->assertEquals(0, (float) $accountsPayable->credited_amount);

        $originalJournal->refresh();
        $this->assertNotNull($originalJournal->reversed_by_id);

        $stockAfterReversal = StockLedger::query()->where('item_id', $this->item->id)->sum('qty_change');
        $this->assertEquals(5, $stockAfterReversal); // back to the full received qty

        // Reversal frees the returned qty/amount for a new Purchase Return.
        $again = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::WRONG_ITEM->value,
            'items' => [['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 2, 'amount' => 40000]],
        ]);
        $this->assertEquals(40000, (float) $again->total_amount);
    }

    public function test_return_against_a_draft_purchase_invoice_is_rejected(): void
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 2, 'rate' => 20000]],
        ]);
        $this->approveDocument($purchaseOrder);
        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 2]],
        ]);
        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);

        $draftInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->expectException(BusinessException::class);

        $this->purchaseReturnService->create([
            'purchase_invoice_id' => $draftInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::DAMAGED_GOODS->value,
            'items' => [['purchase_invoice_item_id' => $draftInvoice->items->first()->id, 'qty_returned' => 1, 'amount' => 20000]],
        ]);
    }

    /** A pure price correction (no physical goods coming back) must not touch stock. */
    public function test_price_correction_with_zero_quantity_moves_no_stock(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 5, rate: 20000);
        $invoiceItem = $purchaseInvoice->items->first();

        $stockBefore = StockLedger::query()->where('item_id', $this->item->id)->count();

        $purchaseReturn = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::PRICE_CORRECTION->value,
            'items' => [['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 0, 'amount' => 10000]],
        ]);
        $this->purchaseReturnService->submit($purchaseReturn);

        $stockAfter = StockLedger::query()->where('item_id', $this->item->id)->count();
        $this->assertEquals($stockBefore, $stockAfter);
    }

    public function test_purchase_return_submission_rolls_back_completely_if_journal_posting_fails(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 5, rate: 20000);
        $invoiceItem = $purchaseInvoice->items->first();

        $purchaseReturn = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::DAMAGED_GOODS->value,
            'items' => [['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 2, 'amount' => 40000]],
        ]);

        ChartOfAccount::query()->where('code', '5050')->update(['is_active' => false]);

        try {
            $this->purchaseReturnService->submit($purchaseReturn);
            $this->fail('Expected submit() to throw when the required chart of account is inactive.');
        } catch (BusinessException) {
        }

        $this->assertEquals(DocumentStatus::DRAFT, $purchaseReturn->fresh()->status);
        $accountsPayable = $purchaseInvoice->accountsPayable()->firstOrFail()->fresh();
        $this->assertEquals(100000, (float) $accountsPayable->amount);
        $this->assertEquals(0, (float) $accountsPayable->credited_amount);
        // No stock movement either — the failed journal post aborts the whole transaction.
        $stock = StockLedger::query()->where('item_id', $this->item->id)->sum('qty_change');
        $this->assertEquals(5, $stock);
    }
}
