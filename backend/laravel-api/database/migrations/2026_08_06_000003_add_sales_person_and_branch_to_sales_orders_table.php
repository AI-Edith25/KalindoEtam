<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignUuid('sales_person_id')->nullable()->after('customer_id')->constrained('sales_persons')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->after('sales_person_id')->constrained('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_person_id');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
