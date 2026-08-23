<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->decimal('allocated_amount', 15, 2)->default(0)->after('total_amount')
                ->comment('Cache only, derived from payment_entry_allocations');
        });

        // Backward compatibility: every existing Payment Entry was fully allocated
        // at creation time under the old model (total_amount == sum of its items),
        // so its allocated_amount starts equal to total_amount — unallocated_amount
        // (total_amount - allocated_amount, computed, not stored) is correctly 0 for
        // all pre-existing records, not a mysterious "fully unapplied" balance. Same
        // reasoning as receipt_entries' own allocated_amount backfill.
        DB::statement('UPDATE payment_entries SET allocated_amount = total_amount');
    }

    public function down(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropColumn('allocated_amount');
        });
    }
};
