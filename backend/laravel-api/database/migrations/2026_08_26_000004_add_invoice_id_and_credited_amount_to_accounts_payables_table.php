<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_payables', function (Blueprint $table) {
            // Nullable — existing rows were created directly from a Goods Receipt (the old
            // path, see GoodsReceiptService::submit()) and have no Purchase Invoice to link.
            $table->foreignUuid('invoice_id')->nullable()->after('supplier_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->decimal('credited_amount', 15, 2)->default(0)->after('paid_amount')
                ->comment('Cache only, derived from purchase_returns. Incremented by PurchaseReturnService::submit(), decremented by ::reverse().');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_payables', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
