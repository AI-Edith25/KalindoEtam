<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two levels only (parent_id on a child, never set on an account that is
 * itself already a parent) — enforced in Store/UpdateChartOfAccountRequest,
 * not here. nullOnDelete: deleting a parent ungroups its children rather
 * than blocking the delete or cascading into them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->foreignUuid('parent_id')->nullable()->after('id')->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
