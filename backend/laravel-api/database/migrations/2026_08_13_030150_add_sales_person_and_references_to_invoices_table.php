<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('sales_person_id')->nullable()->after('customer_id')->constrained('sales_persons')->restrictOnDelete();
            $table->string('reference_1')->nullable()->after('remarks');
            $table->string('reference_2')->nullable()->after('reference_1');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_person_id');
            $table->dropColumn(['reference_1', 'reference_2']);
        });
    }
};
