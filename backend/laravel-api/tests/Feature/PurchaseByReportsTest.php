<?php

namespace Tests\Feature;

use App\Enums\PurchaseReturnReason;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Purchase Report's By Supplier / By Item tabs — sourced from Goods Receipt, net of Returns, never Purchase Order. */
class PurchaseByReportsTest extends TestCase
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

        Permission::query()->firstOrCreate(['name' => 'reports.purchase.view', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('reports.purchase.view');
        Sanctum::actingAs($this->user);
    }

    protected function submittedGoodsReceiptViaPo(int|float $qty, float $rate, ?Supplier $supplier = null): GoodsReceipt
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

        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);
        Sanctum::actingAs($this->user); // approveDocument() above swapped the acting user to a throwaway approver

        return $goodsReceipt;
    }

    protected function submittedDirectReceipt(int|float $qty, float $rate): GoodsReceipt
    {
        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => null,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);

        return $this->goodsReceiptService->submit($goodsReceipt);
    }

    protected function submittedPurchaseInvoiceFor(GoodsReceipt $goodsReceipt): PurchaseInvoice
    {
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->purchaseInvoiceService->submit($purchaseInvoice);
    }

    public function test_by_supplier_and_by_item_totals_match_raw_goods_receipt_minus_returns(): void
    {
        $goodsReceipt = $this->submittedGoodsReceiptViaPo(qty: 10, rate: 20000); // 200000
        $this->submittedDirectReceipt(qty: 5, rate: 10000); // 50000, GR-00011 style, no PO

        $purchaseInvoice = $this->submittedPurchaseInvoiceFor($goodsReceipt);
        $invoiceItem = $purchaseInvoice->items->first();

        $purchaseReturn = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::DAMAGED_GOODS->value,
            'items' => [
                ['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 2, 'amount' => 40000],
            ],
        ]);
        $this->purchaseReturnService->submit($purchaseReturn);

        $rawTotal = (float) DB::table('goods_receipt_items')->sum('amount') - (float) DB::table('purchase_return_items')->sum('amount');
        $this->assertEquals(200000 + 50000 - 40000, $rawTotal);

        $bySupplier = $this->get('/api/v1/reports/purchase/by-supplier')->assertOk()->json('meta.kpis');
        $this->assertEquals($rawTotal, $bySupplier['total_purchases']);
        $this->assertEquals(1, $bySupplier['active_supplier_count']);

        $byItemRows = $this->get('/api/v1/reports/purchase/by-item')->assertOk()->json('data');
        $this->assertCount(1, $byItemRows); // one item, GR-via-PO + Direct Receipt + Return all netted into it
        $this->assertEquals($rawTotal, $byItemRows[0]['amount']);
        $this->assertEquals(10 + 5 - 2, $byItemRows[0]['qty']);
    }

    public function test_direct_receipt_appears_in_by_supplier_and_by_item(): void
    {
        $this->submittedDirectReceipt(qty: 5, rate: 10000);

        $bySupplier = $this->get('/api/v1/reports/purchase/by-supplier')->assertOk()->json('data');
        $this->assertCount(1, $bySupplier);
        $this->assertEquals(1, $bySupplier[0]['receipt_count']);
        $this->assertEquals(50000, $bySupplier[0]['amount']);

        $byItem = $this->get('/api/v1/reports/purchase/by-item')->assertOk()->json('data');
        $this->assertCount(1, $byItem);
        $this->assertEquals(50000, $byItem[0]['amount']);
    }

    public function test_a_return_reduces_qty_and_value(): void
    {
        $goodsReceipt = $this->submittedGoodsReceiptViaPo(qty: 10, rate: 20000);
        $purchaseInvoice = $this->submittedPurchaseInvoiceFor($goodsReceipt);
        $invoiceItem = $purchaseInvoice->items->first();

        $before = $this->get('/api/v1/reports/purchase/by-item')->assertOk()->json('data.0');
        $this->assertEquals(200000, $before['amount']);
        $this->assertEquals(10, $before['qty']);

        $purchaseReturn = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => now()->toDateString(),
            'reason' => PurchaseReturnReason::DAMAGED_GOODS->value,
            'items' => [
                ['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 3, 'amount' => 60000],
            ],
        ]);
        $this->purchaseReturnService->submit($purchaseReturn);

        $after = $this->get('/api/v1/reports/purchase/by-item')->assertOk()->json('data.0');
        $this->assertEquals(200000 - 60000, $after['amount']);
        $this->assertEquals(10 - 3, $after['qty']);
    }

    public function test_by_item_price_stats_and_history(): void
    {
        $this->submittedGoodsReceiptViaPo(qty: 10, rate: 10000);
        $this->travel(1)->days();
        $this->submittedGoodsReceiptViaPo(qty: 5, rate: 12000); // most recent -> "last price"

        $row = $this->get('/api/v1/reports/purchase/by-item')->assertOk()->json('data.0');

        $this->assertEquals((10 * 10000 + 5 * 12000) / 15, $row['avg_price']);
        $this->assertEquals(10000, $row['lowest_price']);
        $this->assertEquals(12000, $row['highest_price']);
        $this->assertEquals(12000, $row['last_price']); // the later-dated receipt, not just the higher rate

        $history = $this->get("/api/v1/reports/purchase/by-item/{$this->item->id}/history")->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertEquals(12000, $history[0]['rate']); // newest first
        $this->assertEquals(10000, $history[1]['rate']);
    }

    public function test_draft_goods_receipt_is_excluded(): void
    {
        $this->goodsReceiptService->create([
            'purchase_order_id' => null,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000]],
        ]); // never submitted -> stays Draft

        $bySupplier = $this->get('/api/v1/reports/purchase/by-supplier')->assertOk()->json('data');
        $this->assertCount(0, $bySupplier);
    }
}
