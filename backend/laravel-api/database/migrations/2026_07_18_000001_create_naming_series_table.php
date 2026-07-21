<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('naming_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('module');
            $table->string('document_type');
            $table->string('prefix')->nullable();
            $table->string('suffix')->nullable();
            $table->unsignedSmallInteger('digit_length')->default(5);
            $table->unsignedInteger('current_number')->default(0);
            $table->boolean('is_default')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_type', 'is_default', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('naming_series');
    }
};
