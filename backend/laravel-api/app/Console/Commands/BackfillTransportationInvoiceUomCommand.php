<?php

namespace App\Console\Commands;

use App\Models\InvoiceItem;
use App\Models\MiscellaneousItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time correction for every Transportation Invoice line created before UOM was wired through
 * (see InvoiceEditorPage's TransportLine + InvoiceService::createTransportation() for the fix) —
 * those rows have uom = null regardless of document status (draft/submitted/cancelled all went
 * through the same code path). There is no misc_item_id stored anywhere on InvoiceItem to look up
 * directly (it was always local-only in the editor, per TransportLine's own docblock), so this
 * matches back onto MiscellaneousItem by its description, trimmed and case-insensitive, the same
 * text the line's item_name was copied from at creation.
 *
 * A description shared by more than one Miscellaneous Item (no uniqueness constraint on that
 * column) is left alone rather than guessed at — reported separately as ambiguous.
 *
 * Idempotent: only touches rows where uom is still null.
 */
class BackfillTransportationInvoiceUomCommand extends Command
{
    protected $signature = 'invoices:backfill-transportation-uom {--dry-run : Compute and report without saving any changes}';

    protected $description = 'Backfill uom on existing Transportation Invoice lines by matching their description back onto a Miscellaneous Item.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $uomByDescription = [];
        $ambiguous = [];

        MiscellaneousItem::query()
            ->withTrashed()
            ->with('uom')
            ->get()
            ->groupBy(fn (MiscellaneousItem $item) => mb_strtolower(trim($item->description)))
            ->each(function ($group, string $key) use (&$uomByDescription, &$ambiguous) {
                $uoms = $group->pluck('uom.name')->filter()->unique();

                if ($uoms->count() > 1) {
                    $ambiguous[] = $key;

                    return;
                }

                if ($uoms->isNotEmpty()) {
                    $uomByDescription[$key] = $uoms->first();
                }
            });

        DB::beginTransaction();

        $updated = 0;
        $noMatch = 0;
        $ambiguousSkipped = 0;

        InvoiceItem::query()
            ->whereHas('invoice', fn ($query) => $query->where('invoice_type', 'transportation'))
            ->whereNull('item_id')
            ->whereNull('uom')
            ->chunkById(500, function ($items) use (&$updated, &$noMatch, &$ambiguousSkipped, $uomByDescription, $ambiguous) {
                foreach ($items as $item) {
                    $key = mb_strtolower(trim((string) $item->item_name));

                    if (in_array($key, $ambiguous, true)) {
                        $ambiguousSkipped++;

                        continue;
                    }

                    if (! isset($uomByDescription[$key])) {
                        $noMatch++;

                        continue;
                    }

                    $item->uom = $uomByDescription[$key];
                    $item->save();
                    $updated++;
                }
            });

        if ($dryRun) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->line('');
        $this->info("Invoice lines updated: {$updated}");
        $this->info("Skipped — no Miscellaneous Item matches that description: {$noMatch}");
        $this->info("Skipped — description matches more than one Miscellaneous Item with different UOMs: {$ambiguousSkipped}");

        return self::SUCCESS;
    }
}
