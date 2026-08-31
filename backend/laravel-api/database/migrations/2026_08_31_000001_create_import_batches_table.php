<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('module');
            $table->string('status')->default('uploaded');
            $table->string('original_filename');
            $table->string('disk')->default('local');
            $table->string('file_path');
            $table->json('mapping')->nullable();
            $table->json('clean_settings')->nullable();
            $table->json('fk_resolutions')->nullable();
            $table->string('commit_mode')->nullable();
            $table->string('write_mode')->nullable();
            $table->json('preview_summary')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('error_report_path')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
