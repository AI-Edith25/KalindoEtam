<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SalesOrder collapses Draft/Submitted/Cancelled into Submitted/Approved/
 * Cancelled — draft (still-editable, just created) becomes the new
 * "submitted", and the old locked "submitted" becomes "approved". No
 * production row has ever held "approved" under the old scheme, so this
 * mapping is a bijection and down() is an exact inverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_orders')->update([
            'status' => DB::raw("CASE status WHEN 'draft' THEN 'submitted' WHEN 'submitted' THEN 'approved' ELSE status END"),
        ]);
    }

    public function down(): void
    {
        DB::table('sales_orders')->update([
            'status' => DB::raw("CASE status WHEN 'submitted' THEN 'draft' WHEN 'approved' THEN 'submitted' ELSE status END"),
        ]);
    }
};
