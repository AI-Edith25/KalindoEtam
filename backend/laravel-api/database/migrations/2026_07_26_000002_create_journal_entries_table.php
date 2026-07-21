<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number')->nullable()->unique();
            $table->string('status')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->date('posting_date');
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->decimal('total_debit', 15, 2)->default(0)->comment('Cache only, derived from journal_entry_lines');
            $table->decimal('total_credit', 15, 2)->default(0)->comment('Cache only, derived from journal_entry_lines');
            $table->foreignUuid('reverses_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUuid('reversed_by_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
