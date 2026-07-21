<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report classification, entirely separate from chart_of_accounts — the
 * accounting master gains zero new columns. See docs/PROFIT_LOSS_DESIGN.md §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_account_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chart_of_account_id')->constrained('chart_of_accounts');
            $table->string('statement_type');
            $table->string('section');
            $table->unsignedInteger('display_order')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['chart_of_account_id', 'statement_type'], 'report_account_mappings_account_statement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_account_mappings');
    }
};
