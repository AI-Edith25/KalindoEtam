<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('no_ktp')->nullable()->after('address');
            $table->string('no_npwp')->nullable()->after('no_ktp');
            $table->string('area')->nullable()->after('no_npwp');
            $table->foreignUuid('sales_person_id')->nullable()->after('area')->constrained('sales_persons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_person_id');
            $table->dropColumn(['no_ktp', 'no_npwp', 'area']);
        });
    }
};
