<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * receipt_entry_items already was a payment-to-invoice allocation record —
 * evolving it in place (rename table + column) instead of building a second,
 * parallel table preserves every existing row (and its FKs) rather than
 * duplicating the concept. See docs/PAYMENT_ALLOCATION_DESIGN.md §2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('receipt_entry_items', 'payment_allocations');

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->renameColumn('received_amount', 'allocated_amount');
            $table->date('allocation_date')->nullable()->after('allocated_amount');
            $table->boolean('is_reversed')->default(false)->after('allocation_date');
        });

        // Backward compatibility: every existing row was allocated at the same
        // moment the payment was received under the old model. Per-row update
        // (not a multi-table UPDATE JOIN) so this runs identically on MySQL
        // and the sqlite in-memory DB the test suite uses; the table is tiny
        // (one row per payment line), so no batching is needed.
        DB::table('payment_allocations')
            ->whereNull('allocation_date')
            ->select('payment_allocations.id', 'receipt_entries.receipt_date')
            ->join('receipt_entries', 'receipt_entries.id', '=', 'payment_allocations.receipt_entry_id')
            ->get()
            ->each(fn ($row) => DB::table('payment_allocations')
                ->where('id', $row->id)
                ->update(['allocation_date' => $row->receipt_date]));
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropColumn(['allocation_date', 'is_reversed']);
            $table->renameColumn('allocated_amount', 'received_amount');
        });

        Schema::rename('payment_allocations', 'receipt_entry_items');
    }
};
