<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra UOMs an Item can be bought/sold in, each with a factor to the item's base UOM
     * (items.uom_id = the stock unit): 1 DUS = 25 KG → conversion_factor 25. The base UOM is
     * implicit (factor 1) and never stored here. No soft deletes — a document line snapshots
     * its own uom_factor, so removing/changing a row never re-values an existing document.
     */
    public function up(): void
    {
        Schema::create('item_uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('conversion_factor', 18, 6);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'uom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_uoms');
    }
};
