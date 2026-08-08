<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional Branch on Outgoing Payment — same rationale as the sibling
     * migration on receipt_entries (see that file's docblock).
     */
    public function up(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->nullable()->after('cash_account_id')->constrained('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
