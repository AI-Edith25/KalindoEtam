<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One target amount per Sales Person per calendar month, optionally split
 * per Branch — see docs on "Target & Pencapaian Sales". The composite
 * unique index below catches every distinct-branch duplicate at the DB
 * level; MySQL treats NULL as distinct in a unique index, so the "no
 * branch" case (branch_id null) needs its own guard — see
 * Store/UpdateSalesTargetRequest's composite Rule::unique()->where() closure,
 * which checks branch_id IS NULL explicitly rather than relying on this
 * index alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_person_id')->constrained('sales_persons')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->unsignedTinyInteger('period_month');
            $table->unsignedSmallInteger('period_year');
            $table->decimal('target_amount', 15, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sales_person_id', 'branch_id', 'period_month', 'period_year'], 'sales_targets_person_branch_period_unique');
            // Achievement panel queries by period alone across every sales person.
            $table->index(['period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
