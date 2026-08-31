<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('header_row')->default(1)->after('file_path');
            $table->unsignedInteger('data_start_row')->default(2)->after('header_row');
            $table->json('field_defaults')->nullable()->after('fk_resolutions');
        });

        Schema::table('import_mapping_presets', function (Blueprint $table) {
            $table->unsignedInteger('header_row')->default(1)->after('name');
            $table->unsignedInteger('data_start_row')->default(2)->after('header_row');
            $table->json('field_defaults')->nullable()->after('clean_settings');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['header_row', 'data_start_row', 'field_defaults']);
        });

        Schema::table('import_mapping_presets', function (Blueprint $table) {
            $table->dropColumn(['header_row', 'data_start_row', 'field_defaults']);
        });
    }
};
