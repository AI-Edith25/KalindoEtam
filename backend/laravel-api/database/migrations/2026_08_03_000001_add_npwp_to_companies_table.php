<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the Company Administration page's field set — see
 * docs/ADMINISTRATION_DESIGN.md §3. Logo is deliberately not a column here:
 * it reuses the existing DocumentAttachment polymorphic upload system
 * (Company as the attachable), so no logo column is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('npwp')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('npwp');
        });
    }
};
