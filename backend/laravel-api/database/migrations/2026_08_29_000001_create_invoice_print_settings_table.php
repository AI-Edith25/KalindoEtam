<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Singleton settings row (Administration > Invoice Print Settings) — the
     * company-wide default for Invoice Print Preview's "Print Options"
     * dialog. Same "one seeded row" shape as purchase_settings; defaults
     * here match InvoicePrintPage's pre-existing hardcoded layout exactly
     * (see shared/lib/printOptions.ts INVOICE_ADVANCED_DEFAULTS on the
     * frontend, kept in sync by hand) so seeding this table never changes
     * an existing invoice's look until an admin actually edits the page.
     */
    public function up(): void
    {
        Schema::create('invoice_print_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('paper_type')->default('a4');
            $table->string('orientation')->default('portrait');
            // Keyed by paper type ({"a4": {"top":12,...}, "continuous": {...}, "half": {...}}) —
            // A4 and Continuous/Half had different hardcoded margins (12mm vs 6mm) before this
            // setting existed, so one flat margin can't represent "no admin override yet" for
            // all three without changing at least one of them on this migration alone.
            $table->json('margins');
            $table->unsignedTinyInteger('scale_percent')->default(100);
            $table->string('font_family')->default('"Times New Roman", "Tinos", "Liberation Serif", serif');
            $table->string('font_size')->default('medium');
            $table->unsignedTinyInteger('qty_decimals')->default(0);
            $table->unsignedTinyInteger('price_decimals')->default(2);
            $table->unsignedTinyInteger('amount_decimals')->default(2);
            $table->string('number_format')->default('en');
            // Unit Price/Line Amount table columns never showed a currency symbol before this
            // setting existed (only the Grand Total line did, via its own hardcoded "RP" literal,
            // unaffected by this flag) — false keeps that exact look until an admin opts in.
            $table->boolean('show_currency_symbol')->default(false);
            $table->boolean('show_discount')->default(false);
            // Ordered list of visible column keys — presence AND order both live in this one
            // array (see PRINT_COLUMN_LABELS on the frontend), so "toggle visible" and "reorder"
            // are the same edit instead of two separate fields to keep in sync.
            $table->json('visible_columns');
            $table->boolean('show_logo')->default(false);
            $table->boolean('show_address')->default(true);
            $table->boolean('show_phone')->default(true);
            $table->boolean('show_email')->default(true);
            $table->text('footer_notes')->nullable();
            $table->boolean('show_signature_block')->default(true);
            $table->string('signature_left_label')->default('AUTHORISED SIGNATURE');
            $table->string('signature_right_label')->default('AUTHORISED SIGNATURE');
            $table->boolean('show_page_number')->default(true);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('invoice_print_settings')->insert([
            'id' => (string) Str::uuid(),
            'visible_columns' => json_encode(['itemCode', 'description', 'sales', 'qty', 'uom', 'unitCost', 'lineAmt']),
            'margins' => json_encode([
                'a4' => ['top' => 12, 'bottom' => 12, 'left' => 12, 'right' => 12],
                'continuous' => ['top' => 6, 'bottom' => 6, 'left' => 6, 'right' => 6],
                'half' => ['top' => 6, 'bottom' => 6, 'left' => 6, 'right' => 6],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_print_settings');
    }
};
