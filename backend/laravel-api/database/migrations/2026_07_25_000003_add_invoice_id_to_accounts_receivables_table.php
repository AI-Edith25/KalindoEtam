<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->foreignUuid('invoice_id')->nullable()->after('customer_id')->constrained('invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
