<?php

namespace Tests\Feature;

use App\Enums\PurchaseReturnReason;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseReturnService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Journal List's Purchase Journal tab — screen data (PurchaseJournalController::index()), document-level. */
class PurchaseJournalTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected PurchaseInvoiceService $purchaseInvoiceService;
    protected PurchaseReturnService $purchaseReturnService;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected Item $item;
    protected User $user;

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

        Permission::query()->firstOrCreate(['name' => 'accounting.journal_list.view', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('accounting.journal_list.view');
        Sanctum::actingAs($this->user);
    }

    protected function submittedPurchaseInvoice(int|float $qty = 10, float $rate = 20000): PurchaseInvoice
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

    public function test_default_view_lists_purchase_invoices(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 10, rate: 20000);

        // submittedPurchaseInvoice() -> approveDocument() swaps the acting user to a
        // dedicated approver mid-flow (see TestCase::approveDocument()) — re-assert the
        // original permissioned user before making the assertion request.
        Sanctum::actingAs($this->user);
        $response = $this->get('/api/v1/purchase-journal')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($purchaseInvoice->document_number, $response->json('data.0.document_number'));
        $this->assertEquals('Acme Supplier', $response->json('data.0.supplier_name'));
        $this->assertEquals(200000, $response->json('data.0.amount_incl_tax'));
    }

    public function test_purchase_return_view_lists_only_returns(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 10, rate: 20000);
        $invoiceItem = $purchaseInvoice->items->first();

        $purchaseReturn = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::DAMAGED_GOODS->value,
            'items' => [['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 3, 'amount' => 60000]],
        ]);
        $this->purchaseReturnService->submit($purchaseReturn);

        Sanctum::actingAs($this->user);
        $response = $this->get('/api/v1/purchase-journal?view=purchase_return')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('purchase_return', $rows[0]['type']);
    }
}
