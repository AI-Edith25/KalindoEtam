<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Narrower cousin of CleanupTestingDataCommand -- purges only the data produced by testing the
 * import-archive features (Customer/Supplier Outstanding Bills, Sales Listing/Product Sales) and
 * resets stock quantities, WITHOUT touching Items/ItemGroups/UOMs (kept as master data) and
 * WITHOUT re-running the full transactional purge (invoices, sales/purchase orders, AR/AP,
 * deliveries, goods receipts, journal entries, ... are left exactly as they are -- this command
 * never lists them, so a stray real transaction created since the last full cleanup survives).
 *
 * stock_ledgers is a shared ledger for BOTH opening-stock/issue/receipt-stock testing AND real
 * Goods Receipt/Delivery/Purchase Return/Credit Note movements (see StockVoucherType) -- wiping
 * it blindly could destroy real transactions, not just test data. dry-run therefore breaks it
 * down by voucher_type so that's visible BEFORE anything is deleted, not discovered after.
 */
class CleanupImportTestDataCommand extends Command
{
    protected $signature = 'cleanup:import-test-data
        {phase : backup|dry-run|execute|verify}
        {--confirm= : Must be exactly "LANJUT HAPUS" to run the execute phase}';

    protected $description = 'Purge import-archive test data (Customer/Supplier Outstanding Bills, Sales Listing/Product Sales) and reset stock quantities, keeping Item/ItemGroup/UOM master data intact.';

    /** Child-to-parent; each pair's child has a cascadeOnDelete FK onto its parent, listed explicitly anyway for clarity. */
    private const ARCHIVE_DELETE_ORDER = [
        'sales_listing_snapshot_lines', 'sales_listing_snapshots',
        'product_sales_snapshot_lines', 'product_sales_snapshots',
        'customer_outstanding_snapshot_lines', 'customer_outstanding_snapshots',
        'supplier_outstanding_snapshot_lines', 'supplier_outstanding_snapshots',
    ];

    /** Every source that can write to stock_ledgers -- same list as CleanupTestingDataCommand's stock section. */
    private const STOCK_DELETE_ORDER = [
        'stock_ledgers', 'stock_ins',
        'fifo_layer_consumptions', 'fifo_layers',
        'stock_adjustment_items', 'stock_adjustments',
        'stock_transfer_items', 'stock_transfers',
        'issue_stock_items', 'issue_stocks',
        'receipt_stock_items', 'receipt_stocks',
        'opening_stock_items', 'opening_stocks',
    ];

    /** Must survive execute() unchanged. */
    private const PROTECTED_TABLES = ['items', 'item_groups', 'uoms'];

    /** Voucher types that mean "real business transaction", not opening-stock/issue/receipt-stock testing. */
    private const NON_TEST_VOUCHER_TYPES = ['goods_receipt', 'delivery', 'purchase_return', 'credit_note', 'stock_in', 'stock_adjustment', 'stock_transfer'];

    public function handle(): int
    {
        return match ($this->argument('phase')) {
            'backup' => $this->runBackup(),
            'dry-run' => $this->runDryRun(),
            'execute' => $this->runExecute(),
            'verify' => $this->runVerify(),
            default => $this->invalidPhase(),
        };
    }

    private function invalidPhase(): int
    {
        $this->error('Phase must be one of: backup, dry-run, execute, verify');

        return self::FAILURE;
    }

    private function runBackup(): int
    {
        $connection = config('database.default');
        $conf = config("database.connections.{$connection}");

        if ($connection !== 'mysql') {
            $this->error("Only 'mysql' is supported by this command (default connection is '{$connection}'). Take a backup manually for this driver.");

            return self::FAILURE;
        }

        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        $filename = 'backup_before_import_cleanup_'.now()->format('Ymd_Hi').'.sql';
        $path = $dir.DIRECTORY_SEPARATOR.$filename;

        $this->info("Running mysqldump for database '{$conf['database']}'...");

        $process = new Process([
            'mysqldump', '--single-transaction', '--quick', '--routines', '--triggers',
            '-h', $conf['host'], '-P', (string) $conf['port'], '-u', $conf['username'], $conf['database'],
        ], env: ['MYSQL_PWD' => $conf['password']], timeout: 600);

        $process->run(function ($type, $buffer) use ($path) {
            if ($type === Process::OUT) {
                File::append($path, $buffer);
            }
        });

        if (! $process->isSuccessful()) {
            $this->error('mysqldump failed: '.$process->getErrorOutput());
            @unlink($path);

            return self::FAILURE;
        }

        clearstatcache();
        $bytes = filesize($path);

        if ($bytes === false || $bytes < 1024) {
            $this->error("Backup file looks wrong (size: {$bytes} bytes). Not trusting it — investigate before proceeding.");

            return self::FAILURE;
        }

        $this->info('Backup created successfully.');
        $this->line("  Path: {$path}");
        $this->line('  Size: '.number_format($bytes / 1024 / 1024, 2).' MB');
        $this->warn('Copy this file off the VPS before continuing.');

        return self::SUCCESS;
    }

    private function runDryRun(): int
    {
        $this->info('Import archive tables that WOULD be deleted:');
        $this->table(['Table', 'Rows'], $this->countRows(self::ARCHIVE_DELETE_ORDER));

        $this->line('');
        $this->info('Stock tables that WOULD be deleted:');
        $this->table(['Table', 'Rows'], $this->countRows(self::STOCK_DELETE_ORDER));

        $this->line('');
        $this->warn('stock_ledgers breakdown by voucher_type -- REVIEW THIS before proceeding:');
        $byType = DB::table('stock_ledgers')->selectRaw('voucher_type, COUNT(*) as row_count')->groupBy('voucher_type')->get();
        if ($byType->isEmpty()) {
            $this->line('  (empty)');
        } else {
            $this->table(['Voucher Type', 'Rows'], $byType->map(fn ($r) => [$r->voucher_type, $r->row_count])->all());
            $nonTest = $byType->filter(fn ($r) => in_array($r->voucher_type, self::NON_TEST_VOUCHER_TYPES, true));
            if ($nonTest->isNotEmpty()) {
                $this->error('WARNING: rows exist with a voucher_type that is normally a REAL transaction (goods_receipt/delivery/purchase_return/credit_note/stock_in/stock_adjustment/stock_transfer), not opening/issue/receipt-stock testing.');
                $this->error('Confirm these are also test data before running execute -- this command cannot tell the difference and will delete them too.');
            }
        }

        $this->line('');
        $stockCount = DB::table('items')->where('current_stock', '!=', 0)->count();
        $this->info("Stock reset plan: {$stockCount} item(s) currently have non-zero current_stock — will be reset to 0. Item/ItemGroup/UOM rows themselves are kept.");

        $this->line('');
        $this->warn('STOP. Review this plan. Run: php artisan cleanup:import-test-data execute --confirm="LANJUT HAPUS" only after explicit confirmation.');

        return self::SUCCESS;
    }

    private function runExecute(): int
    {
        if ($this->option('confirm') !== 'LANJUT HAPUS') {
            $this->error('Refusing to run: pass --confirm="LANJUT HAPUS" (exact phrase) to proceed.');

            return self::FAILURE;
        }

        $backupExists = File::exists(storage_path('app/backups')) && File::glob(storage_path('app/backups/backup_before_import_cleanup_*.sql')) !== [];
        if (! $backupExists) {
            $this->error('No backup_before_import_cleanup_*.sql found in storage/app/backups. Run the `backup` phase first — refusing to proceed without one.');

            return self::FAILURE;
        }

        $protectedCountsBefore = $this->snapshotCounts(self::PROTECTED_TABLES);

        $deleted = [];

        DB::beginTransaction();

        try {
            foreach ([...self::ARCHIVE_DELETE_ORDER, ...self::STOCK_DELETE_ORDER] as $table) {
                $deleted[$table] = DB::table($table)->delete();
            }

            DB::table('items')->update(['current_stock' => 0]);

            $protectedCountsAfter = $this->snapshotCounts(self::PROTECTED_TABLES);
            if ($protectedCountsAfter !== $protectedCountsBefore) {
                throw new \RuntimeException('Item/ItemGroup/UOM row counts changed during cleanup — aborting, this should be impossible.');
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Execution failed, transaction rolled back — no data was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Cleanup executed successfully. Rows deleted per table:');
        $this->table(['Table', 'Rows Deleted'], collect($deleted)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->line('');
        $this->info('items.current_stock reset to 0. Item/ItemGroup/UOM rows untouched.');
        $this->line('Run: php artisan cleanup:import-test-data verify');

        return self::SUCCESS;
    }

    private function runVerify(): int
    {
        $this->info('Archive + stock tables (expect 0 rows each):');
        $rows = $this->countRows([...self::ARCHIVE_DELETE_ORDER, ...self::STOCK_DELETE_ORDER]);
        $this->table(['Table', 'Rows'], $rows);
        $nonZero = array_filter($rows, fn ($r) => $r[1] !== 0);
        if ($nonZero !== []) {
            $this->error('Some tables are NOT empty — investigate before trusting this cleanup.');
        }

        $this->line('');
        $badStock = DB::table('items')->where('current_stock', '!=', 0)->count();
        $this->line("Items with non-zero current_stock: {$badStock} (expect 0)");

        $this->line('');
        $this->info('Item/ItemGroup/UOM counts (must match what you had before -- kept intact):');
        $this->table(['Table', 'Rows'], $this->countRows(self::PROTECTED_TABLES));

        $this->line('');
        $this->warn('Also reload Sales Report and Reports > AR Detail / AP Detail in a browser -- confirm they show empty states again, not stale imported data.');

        return self::SUCCESS;
    }

    /** @param array<int, string> $tables @return array<int, array{0: string, 1: int}> */
    private function countRows(array $tables): array
    {
        return array_map(fn ($t) => [$t, DB::table($t)->count()], $tables);
    }

    /** @param array<int, string> $tables @return array<string, int> */
    private function snapshotCounts(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }
}
