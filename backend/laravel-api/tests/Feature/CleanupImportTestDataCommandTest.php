<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\CustomerOutstandingSnapshot;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ProductSalesSnapshot;
use App\Models\SalesListingSnapshot;
use App\Models\StockLedger;
use App\Models\SupplierOutstandingSnapshot;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Narrower sibling of CleanupTestingDataCommandTest -- exercises the execute-phase safety rails
 * (confirm phrase, backup presence, Item/ItemGroup/UOM protection) plus the actual scope: the 4
 * import archives + every stock-writing table get wiped, current_stock resets to 0, master data
 * survives. The `backup` phase itself needs a real mysqldump binary, tested separately below.
 */
class CleanupImportTestDataCommandTest extends TestCase
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
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);

        $ledger = StockLedger::query()->create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'transaction_type' => StockTransactionType::IN, 'voucher_type' => StockVoucherType::OPENING_STOCK,
            'voucher_id' => (string) \Illuminate\Support\Str::uuid(), 'qty_change' => 50, 'balance_qty' => 50,
            'posting_datetime' => now(),
        ]);

        $salesListing = SalesListingSnapshot::query()->create([
            'period_start' => '2026-08-20', 'period_end' => '2026-09-20', 'source_filename' => 'x.xlsx',
            'total_rows' => 1, 'total_documents' => 1, 'grand_total_amount_excl_tax' => 100, 'grand_total_amount_incl_tax' => 110,
        ]);
        $salesListing->lines()->create([
            'txn_date' => '2026-09-01', 'document_number' => 'SI/1', 'customer_code' => 'C-1', 'customer_name' => 'A',
            'type_code' => 'CusInv', 'amount_excl_tax' => 100, 'disc_adjustment' => 0, 'tax' => 10, 'amount_incl_tax' => 110,
        ]);

        $productSales = ProductSalesSnapshot::query()->create([
            'period_start' => '2026-08-20', 'period_end' => '2026-09-20', 'source_filename' => 'x.xlsx',
            'total_rows' => 1, 'total_items' => 1, 'grand_total_qty' => 10, 'grand_total_amount_excl_tax' => 100,
        ]);
        $productSales->lines()->create([
            'txn_date' => '2026-09-01', 'document_number' => 'SI/1', 'item_code' => 'I-1', 'item_description' => 'Item 1',
            'qty' => 10, 'base_qty' => 10, 'amount_excl_tax' => 100,
        ]);

        $customerArchive = CustomerOutstandingSnapshot::query()->create([
            'source_filename' => 'x.xlsx', 'snapshot_as_of_date' => '2026-09-20',
            'total_rows' => 1, 'total_customers' => 1, 'grand_total_unpaid' => 100, 'grand_total_overdue' => 0,
        ]);
        $customerArchive->lines()->create([
            'customer_code' => 'C-1', 'customer_name' => 'A', 'txn_date' => '2026-09-01', 'ref_no' => 'SI/1',
            'invoice_amount' => 100, 'paid_amount' => 0, 'unpaid_amount' => 100, 'due_date' => '2026-10-01', 'overdue_amount' => 0, 'overdue_days' => 0,
        ]);

        $supplierArchive = SupplierOutstandingSnapshot::query()->create([
            'source_filename' => 'x.xlsx', 'snapshot_as_of_date' => '2026-09-20',
            'total_rows' => 1, 'total_suppliers' => 1, 'grand_total_unpaid' => 100, 'grand_total_overdue' => 0,
        ]);
        $supplierArchive->lines()->create([
            'supplier_code' => 'S-1', 'supplier_name' => 'B', 'txn_date' => '2026-09-01', 'ref_no' => 'PI/1',
            'invoice_amount' => 100, 'paid_amount' => 0, 'unpaid_amount' => 100, 'due_date' => '2026-10-01', 'overdue_amount' => 0, 'overdue_days' => 0,
        ]);

        return compact('itemGroup', 'uom', 'item', 'warehouse', 'ledger', 'salesListing', 'productSales', 'customerArchive', 'supplierArchive');
    }

    public function test_execute_refuses_without_confirm_phrase(): void
    {
        $this->seedOneOfEach();

        $this->artisan('cleanup:import-test-data', ['phase' => 'execute'])->assertExitCode(1);

        $this->assertSame(1, SalesListingSnapshot::count());
    }

    public function test_execute_refuses_with_wrong_confirm_phrase(): void
    {
        $this->seedOneOfEach();

        $this->artisan('cleanup:import-test-data', ['phase' => 'execute', '--confirm' => 'lanjut hapus'])->assertExitCode(1);

        $this->assertSame(1, SalesListingSnapshot::count());
    }

    public function test_execute_refuses_without_a_backup_file_present(): void
    {
        $this->seedOneOfEach();

        $this->artisan('cleanup:import-test-data', ['phase' => 'execute', '--confirm' => 'LANJUT HAPUS'])->assertExitCode(1);

        $this->assertSame(1, SalesListingSnapshot::count());
    }

    public function test_dry_run_reports_but_deletes_nothing_and_flags_real_voucher_types(): void
    {
        $seed = $this->seedOneOfEach();

        // A real-looking movement alongside the opening-stock test data -- dry-run must call this out.
        StockLedger::query()->create([
            'item_id' => $seed['item']->id, 'warehouse_id' => $seed['warehouse']->id,
            'transaction_type' => StockTransactionType::IN, 'voucher_type' => StockVoucherType::GOODS_RECEIPT,
            'voucher_id' => (string) \Illuminate\Support\Str::uuid(), 'qty_change' => 5, 'balance_qty' => 55,
            'posting_datetime' => now(),
        ]);

        $this->artisan('cleanup:import-test-data', ['phase' => 'dry-run'])
            ->expectsOutputToContain('WARNING: rows exist with a voucher_type that is normally a REAL transaction')
            ->assertExitCode(0);

        $this->assertSame(1, SalesListingSnapshot::count());
        $this->assertSame(2, StockLedger::count());
    }

    public function test_execute_deletes_archives_and_stock_but_preserves_item_group_uom(): void
    {
        $seed = $this->seedOneOfEach();

        $masterCountsBefore = [
            'ItemGroup' => ItemGroup::count(),
            'UOM' => UnitOfMeasurement::count(),
            'Item' => Item::count(),
        ];

        File::ensureDirectoryExists($this->backupDir);
        File::put($this->backupDir.'/backup_before_import_cleanup_test.sql', str_repeat('-- dummy backup\n', 100));

        $this->artisan('cleanup:import-test-data', ['phase' => 'execute', '--confirm' => 'LANJUT HAPUS'])->assertExitCode(0);

        $this->assertSame(0, SalesListingSnapshot::count());
        $this->assertSame(0, ProductSalesSnapshot::count());
        $this->assertSame(0, CustomerOutstandingSnapshot::count());
        $this->assertSame(0, SupplierOutstandingSnapshot::count());
        $this->assertSame(0, StockLedger::count());

        $this->assertSame($masterCountsBefore, [
            'ItemGroup' => ItemGroup::count(),
            'UOM' => UnitOfMeasurement::count(),
            'Item' => Item::count(),
        ]);
        $this->assertSame(0, (int) $seed['item']->fresh()->current_stock);

        $this->artisan('cleanup:import-test-data', ['phase' => 'verify'])->assertExitCode(0);
    }

    public function test_backup_phase_refuses_non_mysql_connection(): void
    {
        $this->artisan('cleanup:import-test-data', ['phase' => 'backup'])->assertExitCode(1);
    }
}
