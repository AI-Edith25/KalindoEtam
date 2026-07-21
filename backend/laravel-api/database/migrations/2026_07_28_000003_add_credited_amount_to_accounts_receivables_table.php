<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->decimal('credited_amount', 15, 2)->default(0)->after('paid_amount')
                ->comment('Cache only, derived from credit_notes. Incremented by CreditNoteService::submit(), decremented by ::reverse().');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
        });
    }
};
