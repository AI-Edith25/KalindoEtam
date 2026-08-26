<?php

namespace Tests\Feature;

use App\Enums\AccountsPayableStatus;
use App\Enums\DocumentStatus;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
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
}
