<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line discount — DeliveryService::buildDeliveryLineAttributes()/buildDirectDeliveryLineAttributes()
 * resolve this via DiscountService, same cache-column discipline tax_id/tax_amount already use on
 * this table. discount_type defaults 'amount'/0 so every existing row reads as "no discount".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->string('discount_type')->default('amount')->after('amount');
            $table->decimal('discount_value', 15, 2)->default(0)->after('discount_type');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_value');
            $table->decimal('net_amount', 15, 2)->default(0)->after('discount_amount');
        });

        DB::table('delivery_items')->update(['net_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount', 'net_amount']);
        });
    }
};
