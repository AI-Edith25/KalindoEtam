<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * suppliers_supplier_code_unique was global, so it still blocked a new
 * supplier_code that matched a soft-deleted row. MySQL treats each NULL as
 * distinct in a composite unique key, so (supplier_code, deleted_at) only
 * enforces uniqueness among active rows (deleted_at NULL) while soft-deleted
 * rows (deleted_at set) never collide with each other or with an active row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique('suppliers_supplier_code_unique');
            $table->unique(['supplier_code', 'deleted_at'], 'suppliers_supplier_code_deleted_at_unique');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique('suppliers_supplier_code_deleted_at_unique');
            $table->unique('supplier_code');
        });
    }
};
