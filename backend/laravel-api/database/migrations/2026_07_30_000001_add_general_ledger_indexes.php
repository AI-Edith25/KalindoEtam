<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports every General Ledger query (docs/GENERAL_LEDGER_DESIGN.md §6) —
 * additive only, no existing index touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->index(['chart_of_account_id', 'journal_entry_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['status', 'posting_date']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['chart_of_account_id', 'journal_entry_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['status', 'posting_date']);
        });
    }
};
