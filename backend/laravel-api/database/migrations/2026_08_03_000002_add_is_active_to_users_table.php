<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliberately a new boolean, not a reuse of the existing `deleted_at`
 * soft-delete — see docs/ADMINISTRATION_DESIGN.md §4 Open Question 2.
 * Deactivating a user must stay reversible and must not make the user
 * disappear from creator()/updater() audit-trail relations elsewhere
 * in the app the way a soft-delete would.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
