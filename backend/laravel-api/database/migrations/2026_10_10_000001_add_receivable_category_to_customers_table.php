<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C (Piutang Usaha) / PK (Piutang Karyawan) / PL (Piutang Lain-lain) — see ReceivableCategory
     * enum. Defaults every existing customer to 'C', the only category that existed before this
     * change — zero behavior change for them. See docs/superpowers/specs/
     * 2026-10-10-customer-receivable-categories-design.md.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('receivable_category', 2)->default('C')->after('customer_code');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('receivable_category');
        });
    }
};
