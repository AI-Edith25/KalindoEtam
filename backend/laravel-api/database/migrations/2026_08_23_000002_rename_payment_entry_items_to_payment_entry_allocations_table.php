<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_entry_items already was a payment-to-payable allocation record —
 * evolving it in place (rename table + column) instead of building a second,
 * parallel table preserves every existing row (and its FKs) rather than
 * duplicating the concept. Mirrors 2026_07_27_000002's rename of
 * receipt_entry_items -> payment_allocations, applied to the payable side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('payment_entry_items', 'payment_entry_allocations');

        Schema::table('payment_entry_allocations', function (Blueprint $table) {
            $table->renameColumn('paid_amount', 'allocated_amount');
            $table->date('allocation_date')->nullable()->after('allocated_amount');
            $table->boolean('is_reversed')->default(false)->after('allocation_date');
        });

        // Backward compatibility: every existing row was allocated at the same
        // moment the payment was made under the old model. Per-row update (not a
        // multi-table UPDATE JOIN) so this runs identically on MySQL and the
        // sqlite in-memory DB the test suite uses — same shape as the AR-side
        // rename migration's backfill.
        DB::table('payment_entry_allocations')
            ->whereNull('allocation_date')
            ->select('payment_entry_allocations.id', 'payment_entries.payment_date')
            ->join('payment_entries', 'payment_entries.id', '=', 'payment_entry_allocations.payment_entry_id')
            ->get()
            ->each(fn ($row) => DB::table('payment_entry_allocations')
                ->where('id', $row->id)
                ->update(['allocation_date' => $row->payment_date]));
    }

    public function down(): void
    {
        Schema::table('payment_entry_allocations', function (Blueprint $table) {
            $table->dropColumn(['allocation_date', 'is_reversed']);
            $table->renameColumn('allocated_amount', 'paid_amount');
        });

        Schema::rename('payment_entry_allocations', 'payment_entry_items');
    }
};
