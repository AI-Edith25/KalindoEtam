<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail — never deleted, only flagged reversed. One row per (layer touched,
        // consuming voucher): a single OUT transaction can split across several layers.
        Schema::create('fifo_layer_consumptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fifo_layer_id')->constrained('fifo_layers')->restrictOnDelete();
            $table->string('consuming_source_type');
            $table->uuid('consuming_source_id');
            $table->decimal('qty_consumed', 18, 4);
            // Copied from the layer at consumption time, not a live join — immutable even if
            // the layer's own qty_remaining/cost bookkeeping changes after the fact.
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('total_cost', 18, 2);
            $table->boolean('reversed')->default(false);
            $table->dateTime('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['consuming_source_type', 'consuming_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fifo_layer_consumptions');
    }
};
