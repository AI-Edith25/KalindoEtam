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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Purchase Journal's export — matches the legacy JournalList_Purchase.xlsx/
 * JournalList_PurchaseReturn.xlsx template, minus the Tax Code column (dropped entirely — no
 * purchase line item ever carries a stored tax code, per user decision). Uses the real
 * PO->GoodsReceipt->PurchaseInvoice(->PurchaseReturn) service chain since PurchaseInvoiceItem has
 * a NOT NULL FK to a real GoodsReceiptItem — unlike SalesJournalExportTest, this lets the item
 * explosion path be exercised directly.
 */
class PurchaseJournalExportTest extends TestCase
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
            'receipt_date' => '2026-01-15',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => '2026-01-15',
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->purchaseInvoiceService->submit($purchaseInvoice);
    }

    protected function downloadXlsx(string $query): Worksheet
    {
        // Fixture-building (submittedPurchaseInvoice()/PurchaseReturnService::submit()) may have
        // routed through approveDocument(), which swaps the acting user to a dedicated approver
        // (see TestCase::approveDocument()) — re-assert the original permissioned user here.
        Sanctum::actingAs($this->user);

        $response = $this->get("/api/v1/purchase-journal/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'purchase-journal').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_purchase_invoice_export_has_no_tax_code_column_and_explodes_per_item(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 10, rate: 20000); // grand_total 200000

        $sheet = $this->downloadXlsx('view=purchase_invoice&date_from=2026-01-01&date_to=2026-01-31');

        $this->assertEquals('Transaction', $sheet->getCell('A5')->getValue());
        $this->assertEquals('Salesman Code', $sheet->getCell('G5')->getValue()); // column G is Salesman Code here, not Tax Code
        $this->assertEquals('Purchase Journal', $sheet->getCell('A6')->getValue());

        $this->assertEquals($purchaseInvoice->document_number, $sheet->getCell('A7')->getValue());
        $this->assertEquals('2000 - Accounts Payable - [Purchases, Acme Supplier]', $sheet->getCell('D7')->getValue());
        $this->assertEquals(0, $sheet->getCell('E7')->getValue());
        $this->assertEquals(200000, $sheet->getCell('F7')->getValue());

        $this->assertNull($sheet->getCell('A8')->getValue());
        $this->assertEquals('5100 - Purchase Expense - [Widget]', $sheet->getCell('D8')->getValue());
        $this->assertEquals(200000, $sheet->getCell('E8')->getValue());
        $this->assertEquals(0, $sheet->getCell('F8')->getValue());

        $this->assertEquals('Total For :[Purchase Journal]', $sheet->getCell('A9')->getValue());
        $this->assertEquals(200000, $sheet->getCell('E9')->getValue());
        $this->assertEquals(200000, $sheet->getCell('F9')->getValue());
    }

    public function test_purchase_return_export_group_label_and_ref1_inherited_from_parent_invoice(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice(qty: 10, rate: 20000);
        $purchaseInvoice->update(['reference_number' => 'SUPPLIER-INV-001']);
        $invoiceItem = $purchaseInvoice->items->first();

        $purchaseReturn = $this->purchaseReturnService->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => '2026-01-20',
            'reason' => PurchaseReturnReason::DAMAGED_GOODS->value,
            'items' => [['purchase_invoice_item_id' => $invoiceItem->id, 'qty_returned' => 3, 'amount' => 60000]],
        ]);
        $this->purchaseReturnService->submit($purchaseReturn);

        $sheet = $this->downloadXlsx('view=purchase_return&date_from=2026-01-01&date_to=2026-01-31');

        $this->assertEquals('Purchase Return Journal', $sheet->getCell('A6')->getValue());
        $this->assertEquals($purchaseReturn->document_number, $sheet->getCell('A7')->getValue());
        $this->assertEquals('SUPPLIER-INV-001', $sheet->getCell('C7')->getValue()); // Ref. 1 # inherited from the parent Purchase Invoice
        $this->assertEquals('2000 - Accounts Payable - [Purchase Return, Acme Supplier]', $sheet->getCell('D7')->getValue());
        $this->assertEquals(60000, $sheet->getCell('E7')->getValue());
        $this->assertEquals(0, $sheet->getCell('F7')->getValue());
        $this->assertEquals('Total For :[Purchase Return Journal]', $sheet->getCell('A9')->getValue());
    }
}
