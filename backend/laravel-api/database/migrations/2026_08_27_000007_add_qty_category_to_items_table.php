<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decides whether this item's qty is integer-only ('unit', e.g. zak/pcs)
     * or 2-decimal ('weight', e.g. bulk cement weighed on a truck scale) —
     * see App\Enums\QtyCategory. Lives on the Item itself, not on its UoM
     * (confirmed with the client: the same UoM can appear on differently-
     * counted items). Every existing item defaults to 'unit' — identical to
     * the current all-integer qty behavior — the user reclassifies specific
     * items to 'weight' via the Item edit form after deploy.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('qty_category')->default('unit')->after('current_stock');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('qty_category');
        });
    }
};
