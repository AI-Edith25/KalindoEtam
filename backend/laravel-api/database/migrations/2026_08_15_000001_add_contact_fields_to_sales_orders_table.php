<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('attention')->nullable()->after('remarks');
            $table->string('tel')->nullable()->after('attention');
            $table->string('fax')->nullable()->after('tel');
            $table->string('reference')->nullable()->after('fax');
            $table->foreignUuid('terms_of_payment_id')->nullable()->after('reference')->constrained('terms_of_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terms_of_payment_id');
            $table->dropColumn(['attention', 'tel', 'fax', 'reference']);
        });
    }
};
