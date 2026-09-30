<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports editing a Complete Delivery (DeliveryService::updateComplete()). sales_person_id/
 * attention/tel/fax are per-Delivery overrides — today these are only ever displayed via
 * `delivery.sales_order->sales_person/attention/tel/fax` (DeliveryDetailPage.tsx); null keeps
 * showing the Sales Order's own value (same fallback convention Invoice.reference_1 already uses
 * for the SO's document number), a non-null value is this Delivery's own correction, never
 * mutating the shared Sales Order row. lock_version is a plain optimistic-lock counter — new to
 * this codebase (the existing `revision` column is a dormant display field, never incremented).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignUuid('sales_person_id')->nullable()->after('customer_id')->constrained('sales_persons')->nullOnDelete();
            $table->string('attention')->nullable()->after('driver');
            $table->string('tel')->nullable()->after('attention');
            $table->string('fax')->nullable()->after('tel');
            $table->unsignedInteger('lock_version')->default(1)->after('revision');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropForeign(['sales_person_id']);
            $table->dropColumn(['sales_person_id', 'attention', 'tel', 'fax', 'lock_version']);
        });
    }
};
