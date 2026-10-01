<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Generic Manual/Import marker + duplicate-rejection backstop, shared by every document type that
 * has both a manual entry form and an import flow (Invoice, PurchaseOrder, GoodsReceipt,
 * ReceiptEntry, PaymentEntry).
 *
 * `source` is deliberately separate from Invoice/PurchaseOrder's existing `import_source_type` —
 * that column encodes a finer-grained import *flavor* ('historical_invoice' vs
 * 'po_tracking_amount') that InvoiceService::submit()/cancel() already key off to skip stock/AR/GL
 * side effects; this migration must not disturb that. `source` is only ever a plain Manual/Import
 * badge.
 *
 * The *_normalized columns (UPPER+TRIM of the existing document/reference number) are what the
 * real duplicate-rejection unique index is built on — comparing the raw column directly would miss
 * "SI/KE/00001/09/2026" vs " si/ke/00001/09/2026" being the same number, and would depend on the
 * database's collation to catch even the case difference.
 *
 * Pre-existing duplicate normalized values (messy legacy data) are reported via Log::warning and
 * the unique index is skipped for that table rather than failing the whole deploy's migration run
 * — per the business requirement to report old duplicates, never silently delete or block on them.
 */
return new class extends Migration
{
    private const TARGETS = [
        'invoices' => ['number_column' => 'source_document_number', 'backfill_source_where' => 'import_source_type IS NOT NULL OR source_document_number IS NOT NULL'],
        'purchase_orders' => ['number_column' => 'source_document_number', 'backfill_source_where' => 'import_source_type IS NOT NULL OR source_document_number IS NOT NULL'],
        'goods_receipts' => ['number_column' => 'source_document_number', 'backfill_source_where' => 'source_document_number IS NOT NULL'],
        'receipt_entries' => ['number_column' => 'reference_number', 'backfill_source_where' => null],
        'payment_entries' => ['number_column' => 'reference_number', 'backfill_source_where' => null],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $config) {
            $normalizedColumn = "{$config['number_column']}_normalized";

            Schema::table($table, function (Blueprint $blueprint) use ($normalizedColumn) {
                $blueprint->string('source', 10)->default('manual')->index();
                $blueprint->foreignUuid('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
                $blueprint->dateTime('imported_at')->nullable();
                $blueprint->string($normalizedColumn)->nullable()->index();
            });

            if ($config['backfill_source_where'] !== null) {
                DB::statement("UPDATE {$table} SET source = 'import' WHERE {$config['backfill_source_where']}");
            }

            DB::statement(
                "UPDATE {$table} SET {$normalizedColumn} = UPPER(TRIM({$config['number_column']})) ".
                "WHERE {$config['number_column']} IS NOT NULL AND TRIM({$config['number_column']}) <> ''"
            );

            $this->addUniqueIndexUnlessDuplicates($table, $normalizedColumn);
        }
    }

    private function addUniqueIndexUnlessDuplicates(string $table, string $normalizedColumn): void
    {
        $duplicates = DB::table($table)
            ->select($normalizedColumn)
            ->whereNotNull($normalizedColumn)
            ->groupBy($normalizedColumn)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($normalizedColumn);

        if ($duplicates->isNotEmpty()) {
            Log::warning("Skipping unique index on {$table}.{$normalizedColumn} — pre-existing duplicate values found, review and resolve manually.", [
                'table' => $table,
                'column' => $normalizedColumn,
                'duplicate_values' => $duplicates->all(),
            ]);

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($normalizedColumn) {
            $blueprint->unique($normalizedColumn);
        });
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $config) {
            $normalizedColumn = "{$config['number_column']}_normalized";

            Schema::table($table, function (Blueprint $blueprint) use ($normalizedColumn) {
                $blueprint->dropForeign(['import_batch_id']);
                $blueprint->dropColumn(['source', 'import_batch_id', 'imported_at', $normalizedColumn]);
            });
        }
    }
};
