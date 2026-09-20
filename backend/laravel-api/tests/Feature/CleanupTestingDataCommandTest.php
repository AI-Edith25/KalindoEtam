<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\NamingSeries;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\UnitOfMeasurement;
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

    public function test_backup_phase_refuses_non_mysql_connection(): void
    {
        // Test env runs on sqlite; the backup phase must refuse rather than
        // silently doing nothing or dumping the wrong thing.
        $this->artisan('cleanup:testing-data', ['phase' => 'backup'])
            ->assertExitCode(1);
    }
}
