<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A period's coverage is checked against its own start_date/end_date at
 * read time — journal_entries gains zero new columns (no stored
 * accounting_period_id on journal lines). See docs/PERIOD_CLOSING_DESIGN.md §1.
 * closed_by_id/closed_at/reopened_by_id/reopened_at hold only the latest
 * close/reopen event — the full multi-cycle history lives in the existing
 * DocumentTimeline mechanism (§4), not a second history table here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fiscal_year_id')->constrained('fiscal_years');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status'); // PeriodStatus: 'open' | 'closed' — two states only, see §1
            $table->foreignUuid('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('reopened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['fiscal_year_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
