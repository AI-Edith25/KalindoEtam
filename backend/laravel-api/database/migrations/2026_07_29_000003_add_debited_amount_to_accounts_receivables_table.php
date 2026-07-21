<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->decimal('debited_amount', 15, 2)->default(0)->after('credited_amount')->comment('Cache only, derived from debit_notes');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->dropColumn('debited_amount');
        });
    }
};
