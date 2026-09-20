<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\AccountsPayable;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\NamingSeries;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Exercises the execute-phase safety rails directly (confirm phrase, backup
 * presence, master-table protection, FK-ordered delete, stock/sequence
 * reset) against sqlite. The `backup` phase itself needs a real mysqldump
 * binary + MySQL connection, so it isn't exercised here — see its own
 * mysql-only guard, tested separately below.
 */
class CleanupTestingDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = storage_path('app/backups');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);

        parent::tearDown();
    }

    private function seedOneOfEach(): array
    {
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg',
            'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id,
            'standard_rate' => 60000, 'current_stock' => 50,
        ]);
        $customer = Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);

        $series = NamingSeries::query()->create([
            'module' => 'sales', 'document_type' => 'sales',
            'prefix' => 'SO/', 'digit_length' => 5, 'current_number' => 7,
        ]);

        $so = SalesOrder::query()->create([
            'customer_id' => $customer->id,
            'order_date' => now(),
        ]);
        $soItem = SalesOrderItem::query()->create([
            'sales_order_id' => $so->id, 'item_id' => $item->id,
            'qty' => 5, 'rate' => 60000, 'amount' => 300000,
        ]);

        return compact('itemGroup', 'uom', 'item', 'customer', 'so', 'soItem', 'series');
    }

    public function test_execute_refuses_without_confirm_phrase(): void
    {
        $this->seedOneOfEach();

        $this->artisan('cleanup:testing-data', ['phase' => 'execute'])
            ->assertExitCode(1);

        $this->assertSame(1, SalesOrder::count());
    }

    public function test_execute_refuses_with_wrong_confirm_phrase(): void
    {
        $this->seedOneOfEach();

        $this->artisan('cleanup:testing-data', ['phase' => 'execute', '--confirm' => 'lanjut hapus'])
            ->assertExitCode(1);

        $this->assertSame(1, SalesOrder::count());
    }

    public function test_execute_refuses_without_a_backup_file_present(): void
    {
        $this->seedOneOfEach();

        $this->artisan('cleanup:testing-data', ['phase' => 'execute', '--confirm' => 'LANJUT HAPUS'])
            ->assertExitCode(1);

        $this->assertSame(1, SalesOrder::count());
    }

    public function test_dry_run_reports_but_deletes_nothing(): void
    {
        $this->seedOneOfEach();

        $this->artisan('cleanup:testing-data', ['phase' => 'dry-run'])
            ->assertExitCode(0);

        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(1, SalesOrderItem::count());
    }

    public function test_execute_deletes_transactional_data_and_preserves_master_data(): void
    {
        $seed = $this->seedOneOfEach();

        // A few naming_series rows are seeded by data migrations themselves
        // (e.g. seed_customer_naming_series), so the baseline isn't empty —
        // assert against counts captured right after fixture setup, not literals.
        $masterCountsBefore = [
            'ItemGroup' => ItemGroup::count(),
            'UOM' => UnitOfMeasurement::count(),
            'Item' => Item::count(),
            'Customer' => Customer::count(),
            'NamingSeries' => NamingSeries::count(),
        ];

        File::ensureDirectoryExists($this->backupDir);
        File::put($this->backupDir.'/backup_before_cleanup_test.sql', str_repeat('-- dummy backup\n', 100));

        $this->artisan('cleanup:testing-data', ['phase' => 'execute', '--confirm' => 'LANJUT HAPUS'])
            ->assertExitCode(0);

        $this->assertSame(0, SalesOrder::count());
        $this->assertSame(0, SalesOrderItem::count());

        // Master data untouched by execute.
        $this->assertSame($masterCountsBefore, [
            'ItemGroup' => ItemGroup::count(),
            'UOM' => UnitOfMeasurement::count(),
            'Item' => Item::count(),
            'Customer' => Customer::count(),
            'NamingSeries' => NamingSeries::count(),
        ]);

        // Stock cache and document numbering reset, master rows kept.
        $this->assertSame(0, (int) $seed['item']->fresh()->current_stock);
        $this->assertSame(0, $seed['series']->fresh()->current_number);

        $this->artisan('cleanup:testing-data', ['phase' => 'verify'])
            ->assertExitCode(0);
    }

    /**
     * Regression test for a production failure: accounts_receivables.invoice_id
     * and accounts_payables.invoice_id are FKs bolted on by later ALTER
     * migrations (restrictOnDelete), missed on the first pass because only
     * Schema::create was checked. Also covers journal_entries' mutual
     * self-referencing reverses_id/reversed_by_id, which can block a bulk
     * delete of the table against itself unless nulled out first.
     */
    public function test_execute_handles_full_document_chain_and_self_referencing_journal_entries(): void
    {
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg',
            'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $customer = Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);
        $supplier = Supplier::query()->create(['supplier_code' => 'SUP1', 'supplier_name' => 'Test Supplier']);

        foreach (['sales', 'delivery', 'invoice_goods', 'purchase', 'goods_receipt', 'purchase_invoice', 'journal'] as $documentType) {
            NamingSeries::query()->create(['module' => 'test', 'document_type' => $documentType, 'digit_length' => 5]);
        }

        // Sales chain -> AccountsReceivable.invoice_id (the FK that broke production).
        $so = SalesOrder::query()->create(['customer_id' => $customer->id, 'order_date' => now()]);
        $soItem = SalesOrderItem::query()->create(['sales_order_id' => $so->id, 'item_id' => $item->id, 'qty' => 5, 'rate' => 60000, 'amount' => 300000]);
        $delivery = Delivery::query()->create(['sales_order_id' => $so->id, 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'delivery_date' => now(), 'due_date' => now()->addDays(30)]);
        $deliveryItem = DeliveryItem::query()->create(['delivery_id' => $delivery->id, 'sales_order_item_id' => $soItem->id, 'item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'uom' => 'Zak', 'rate' => 60000, 'qty' => 5, 'amount' => 300000]);
        $invoice = Invoice::query()->create(['delivery_id' => $delivery->id, 'sales_order_id' => $so->id, 'customer_id' => $customer->id, 'invoice_date' => now(), 'due_date' => now()->addDays(30)]);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'delivery_item_id' => $deliveryItem->id, 'item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'uom' => 'Zak', 'rate' => 60000, 'qty' => 5, 'amount' => 300000]);
        AccountsReceivable::query()->create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'sales_order_id' => $so->id, 'delivery_id' => $delivery->id, 'reference_number' => $delivery->id, 'amount' => 300000, 'due_date' => now()->addDays(30)]);

        // Purchase chain -> AccountsPayable.invoice_id (the mirror-image FK).
        $po = PurchaseOrder::query()->create(['supplier_id' => $supplier->id, 'order_date' => now()]);
        $poItem = PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'item_id' => $item->id, 'qty' => 5, 'rate' => 50000, 'amount' => 250000]);
        $gr = GoodsReceipt::query()->create(['purchase_order_id' => $po->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'due_date' => now()->addDays(30)]);
        $grItem = GoodsReceiptItem::query()->create(['goods_receipt_id' => $gr->id, 'purchase_order_item_id' => $poItem->id, 'item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'uom' => 'Zak', 'qty' => 5, 'rate' => 50000, 'amount' => 250000]);
        $pi = PurchaseInvoice::query()->create(['goods_receipt_id' => $gr->id, 'purchase_order_id' => $po->id, 'supplier_id' => $supplier->id, 'invoice_date' => now(), 'due_date' => now()->addDays(30)]);
        PurchaseInvoiceItem::query()->create(['purchase_invoice_id' => $pi->id, 'goods_receipt_item_id' => $grItem->id, 'item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'uom' => 'Zak', 'qty' => 5, 'rate' => 50000, 'amount' => 250000]);
        AccountsPayable::query()->create(['supplier_id' => $supplier->id, 'invoice_id' => $pi->id, 'purchase_order_id' => $po->id, 'goods_receipt_id' => $gr->id, 'reference_number' => $gr->id, 'amount' => 250000, 'due_date' => now()->addDays(30)]);

        // Mutually self-referencing journal entries.
        $je1 = JournalEntry::query()->create(['posting_date' => now()]);
        $je2 = JournalEntry::query()->create(['posting_date' => now(), 'reverses_id' => $je1->id]);
        $je1->update(['reversed_by_id' => $je2->id]);

        File::ensureDirectoryExists($this->backupDir);
        File::put($this->backupDir.'/backup_before_cleanup_test.sql', str_repeat('-- dummy backup\n', 100));

        $this->artisan('cleanup:testing-data', ['phase' => 'execute', '--confirm' => 'LANJUT HAPUS'])
            ->assertExitCode(0);

        $this->assertSame(0, AccountsReceivable::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, AccountsPayable::count());
        $this->assertSame(0, PurchaseInvoice::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_backup_phase_refuses_non_mysql_connection(): void
    {
        // Test env runs on sqlite; the backup phase must refuse rather than
        // silently doing nothing or dumping the wrong thing.
        $this->artisan('cleanup:testing-data', ['phase' => 'backup'])
            ->assertExitCode(1);
    }
}
