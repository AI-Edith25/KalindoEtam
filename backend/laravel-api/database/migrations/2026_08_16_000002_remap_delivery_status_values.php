<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delivery collapses Draft/Submitted/Cancelled into Pending/Complete —
 * draft becomes "pending", the old "submitted" (stock already moved,
 * DeliveryService::submit()/complete() is unchanged logic, just renamed)
 * becomes "complete". This is a plain column UPDATE, not a call through
 * DeliveryService::complete(), so no stock movement is re-triggered for
 * rows that already had it applied under the old "submitted" status.
 * Delivery::cancel() has always thrown (no route ever exposed it, see
 * Delivery::cancel()), so no production row should hold "cancelled" —
 * defensively folded into "pending" (the safe idle state) rather than
 * left to break the DeliveryStatus enum cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deliveries')->update([
            'status' => DB::raw("CASE WHEN status = 'submitted' THEN 'complete' ELSE 'pending' END"),
        ]);
    }

    public function down(): void
    {
        DB::table('deliveries')->update([
            'status' => DB::raw("CASE WHEN status = 'complete' THEN 'submitted' ELSE 'draft' END"),
        ]);
    }
};
