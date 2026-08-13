<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D2 (UAT review 2026-08-12): revives payment_method as a live,
     * required field (it already exists on this table, nullable, kept
     * fillable/cast since the Aug 8 cash_account_id migration — see that
     * migration's own comment) alongside the Giro/Cek number and due date,
     * shown conditionally only when payment_method is giro/cheque.
     */
    public function up(): void
    {
        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->string('giro_number')->nullable()->after('payment_method');
            $table->date('giro_due_date')->nullable()->after('giro_number');
        });
    }

    public function down(): void
    {
        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->dropColumn(['giro_number', 'giro_due_date']);
        });
    }
};
