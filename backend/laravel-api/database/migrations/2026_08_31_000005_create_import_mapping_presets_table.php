<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('module');
            $table->string('name');
            $table->json('mapping');
            $table->json('clean_settings')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['module', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mapping_presets');
    }
};
