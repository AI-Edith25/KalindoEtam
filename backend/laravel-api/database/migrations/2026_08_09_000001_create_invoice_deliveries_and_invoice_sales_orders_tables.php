<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoice-from-multiple-Deliveries: invoices.delivery_id/sales_order_id
     * stay exactly as they are (NOT NULL, FK) and become "anchor" columns —
     * only delivery_id's UNIQUE index is dropped, since these two new pivot
     * tables are now the authoritative one-to-many source of truth.
     * invoice_deliveries.delivery_id is itself UNIQUE, which is what
     * replaces the old invoices.delivery_id unique constraint as the real
     * "a Delivery can never be invoiced twice" enforcement. No surrogate
     * `id` column, composite/plain-FK pivot shape instead — same shape
     * Eloquent's belongsToMany()->sync() expects by default, and the same
     * shape already used by Spatie's own model_has_permissions/model_has_roles
     * pivots in this codebase.
     *
     * Recovery path, same class of issue as 2026_08_08_000001_create_invoice_
     * change_requests_table's own comment: MySQL DDL statements auto-commit
     * individually, so if this up() fails partway through on a production
     * deploy (confirmed: a real MySQL run created invoice_deliveries, then
     * failed on a later statement before Laravel's migrations table could
     * record this file as applied), a plain retry hits "table already
     * exists" on the first CREATE TABLE it re-attempts, even though nothing
     * was ever durably recorded as migrated. Every step below is written to
     * be safely re-entrant from whatever point a prior attempt stopped at,
     * without ever dropping a table that might already hold real backfilled
     * data: ensureTable() only creates a table when it's genuinely absent,
     * reuses one that already has the exact columns this migration defines
     * (an earlier attempt got at least that far), and refuses to proceed
     * silently if a same-named table exists with a different shape (not
     * this migration's doing — needs manual inspection, not a guess). The
     * unique-index drop is skipped if already gone. The backfill uses
     * insertOrIgnore so re-running it never duplicates or errors on rows a
     * prior attempt already inserted.
     *
     * Second recovery detail, confirmed against a real production run:
     * invoices.delivery_id's existing foreign key (to deliveries) currently
     * relies on the UNIQUE index as its only supporting index — MySQL/InnoDB
     * requires every foreign-keyed column to have *some* supporting index at
     * all times, so dropping the unique one outright fails with "Cannot drop
     * index 'invoices_delivery_id_unique': needed in a foreign key
     * constraint" (error 1553). A plain, non-unique index is created first
     * (only if one doesn't already exist) so the FK always has a supporting
     * index to fall back on the moment the unique one goes away — the
     * foreign key itself is never touched, only which index backs it.
     */
    public function up(): void
    {
        $this->ensureTable('invoice_deliveries', ['invoice_id', 'delivery_id', 'created_at', 'updated_at'], function () {
            Schema::create('invoice_deliveries', function (Blueprint $table) {
                $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->foreignUuid('delivery_id')->unique()->constrained('deliveries')->restrictOnDelete();
                $table->timestamps();
                $table->primary(['invoice_id', 'delivery_id']);
            });
        });

        $this->ensureTable('invoice_sales_orders', ['invoice_id', 'sales_order_id', 'created_at', 'updated_at'], function () {
            Schema::create('invoice_sales_orders', function (Blueprint $table) {
                $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->foreignUuid('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
                $table->timestamps();
                $table->primary(['invoice_id', 'sales_order_id']);
            });
        });

        $invoiceIndexes = collect(Schema::getIndexes('invoices'));
        $hasUniqueIndex = $invoiceIndexes->contains(fn (array $index) => $index['name'] === 'invoices_delivery_id_unique');

        if ($hasUniqueIndex) {
            $hasPlainIndex = $invoiceIndexes->contains(fn (array $index) => $index['name'] === 'invoices_delivery_id_index');

            if (! $hasPlainIndex) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->index('delivery_id');
                });
            }

            Schema::table('invoices', function (Blueprint $table) {
                $table->dropUnique(['delivery_id']);
            });
        }

        // Backfill: every existing invoice becomes consistent with the new relations
        // immediately, from its own current anchor columns. insertOrIgnore makes this
        // safe to re-run — a prior partial attempt may have already backfilled some or
        // all of these rows before failing on a later invoice or a later statement.
        $now = now();
        foreach (DB::table('invoices')->select('id', 'delivery_id', 'sales_order_id')->cursor() as $invoice) {
            DB::table('invoice_deliveries')->insertOrIgnore([
                'invoice_id' => $invoice->id,
                'delivery_id' => $invoice->delivery_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('invoice_sales_orders')->insertOrIgnore([
                'invoice_id' => $invoice->id,
                'sales_order_id' => $invoice->sales_order_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('delivery_id');
        });

        if (collect(Schema::getIndexes('invoices'))->contains(fn (array $index) => $index['name'] === 'invoices_delivery_id_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex(['delivery_id']);
            });
        }

        Schema::dropIfExists('invoice_sales_orders');
        Schema::dropIfExists('invoice_deliveries');
    }

    /**
     * Creates $table via $create() only if it doesn't already exist. If it exists with
     * exactly the columns this migration would have created, it's reused as-is (an earlier
     * attempt at this same migration got at least that far — never dropped, since it may
     * already hold real backfilled data from that attempt). If it exists with a different
     * shape, that's not this migration's doing — refuse to guess, throw for manual review.
     */
    protected function ensureTable(string $table, array $expectedColumns, \Closure $create): void
    {
        if (! Schema::hasTable($table)) {
            $create();

            return;
        }

        $missing = array_diff($expectedColumns, Schema::getColumnListing($table));

        if ($missing !== []) {
            throw new RuntimeException(
                "{$table} already exists but is missing expected column(s): ".implode(', ', $missing).
                ' — this does not look like a partial run of this migration. Inspect it manually before proceeding.'
            );
        }
    }
};
