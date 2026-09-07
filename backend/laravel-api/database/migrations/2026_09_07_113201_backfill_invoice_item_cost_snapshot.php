<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time historical backfill for invoice_items.unit_cost/cost_amount — every Goods invoice
     * line created before this ticket has real COGS data sitting in fifo_layer_consumptions
     * (consuming_source_type='delivery', consuming_source_id=<the line's Delivery>), it just was
     * never copied onto the invoice line. Same "read what actually happened, never a guessed
     * current cost" posture as BackfillFifoLayersCommand.
     *
     * One SELECT resolves every row's cost via portable query-builder joins (works on both MySQL
     * prod and the sqlite test DB), then a per-row UPDATE applies it — same acceptable one-time
     * cost as BackfillFifoLayersCommand's per-document loop, not a hot path.
     */
    public function up(): void
    {
        $rows = DB::table('invoice_items as ii')
            ->join('delivery_items as di', 'di.id', '=', 'ii.delivery_item_id')
            ->join('fifo_layer_consumptions as flc', function ($join) {
                $join->on('flc.consuming_source_id', '=', 'di.delivery_id')
                    ->where('flc.consuming_source_type', '=', 'delivery');
            })
            ->join('fifo_layers as fl', function ($join) {
                $join->on('fl.id', '=', 'flc.fifo_layer_id')
                    ->on('fl.item_id', '=', 'ii.item_id');
            })
            ->whereNull('ii.cost_amount')
            ->whereNotNull('ii.delivery_item_id')
            ->groupBy('ii.id', 'ii.qty')
            ->select('ii.id', 'ii.qty')
            ->selectRaw('SUM(flc.qty_consumed) as qty_consumed')
            ->selectRaw('SUM(flc.total_cost) as total_cost')
            ->get();

        foreach ($rows as $row) {
            if ((float) $row->qty_consumed <= 0) {
                continue;
            }

            $unitCost = round((float) $row->total_cost / (float) $row->qty_consumed, 2);

            DB::table('invoice_items')->where('id', $row->id)->update([
                'unit_cost' => $unitCost,
                'cost_amount' => round($unitCost * (float) $row->qty, 2),
            ]);
        }
    }

    /** One-way data fix — same posture as fifo:backfill, nothing meaningful to undo. */
    public function down(): void {}
};
