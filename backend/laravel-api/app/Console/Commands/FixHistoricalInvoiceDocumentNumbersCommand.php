<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time correction: every Invoice imported via SalesInvoiceImportService got a brand-new
 * document_number minted by the live naming series at creation (Documentable only skips
 * generation when document_number is already set — this import never set it, before the
 * matching fix in SalesInvoiceImportService::createGoodsInvoice()/createTransportationInvoice()).
 * The result: document_number (what every screen, search, print and the invoice's own
 * AccountsReceivable.reference_number show) never matched source_document_number (the real
 * legacy number from the file) — a user searching the web app for a document by its original
 * number could never find it. Confirmed 0 collisions and 0 duplicate legacy numbers across all
 * 6004 affected rows before writing this (see chat history 2026-10-02).
 *
 * Also repairs reference_number on any AccountsReceivable row already created for one of these
 * invoices (by BackfillHistoricalInvoiceAccountsReceivableCommand, which copies document_number
 * at AR-creation time) — that copy is frozen at creation and won't pick up this fix on its own.
 *
 * Idempotent: an invoice whose document_number already equals source_document_number is skipped.
 */
class FixHistoricalInvoiceDocumentNumbersCommand extends Command
{
    protected $signature = 'invoices:fix-historical-document-numbers {--dry-run : Compute and report without saving any changes}';

    protected $description = 'Replace the auto-generated document_number on every historical-imported Invoice with its real legacy number (source_document_number), and repair any AR row already copied from the wrong one.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        $updated = 0;
        $arFixed = 0;
        $skippedCollision = [];

        Invoice::query()
            ->where('import_source_type', 'historical_invoice')
            ->whereColumn('document_number', '!=', 'source_document_number')
            ->with('accountsReceivable')
            ->chunkById(500, function ($invoices) use (&$updated, &$arFixed, &$skippedCollision) {
                foreach ($invoices as $invoice) {
                    $legacyNumber = $invoice->source_document_number;

                    $ownedByAnother = Invoice::query()
                        ->where('document_number', $legacyNumber)
                        ->where('id', '!=', $invoice->id)
                        ->exists();

                    if ($ownedByAnother) {
                        $skippedCollision[] = $legacyNumber;

                        continue;
                    }

                    $invoice->update(['document_number' => $legacyNumber]);
                    $updated++;

                    $ar = $invoice->accountsReceivable;
                    if ($ar !== null && $ar->reference_number !== $legacyNumber) {
                        $ar->update(['reference_number' => $legacyNumber]);
                        $arFixed++;
                    }
                }
            });

        if ($dryRun) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->line('');
        $this->info("Invoice document numbers corrected: {$updated}");
        $this->info("AccountsReceivable reference numbers repaired: {$arFixed}");
        $this->info('Skipped — legacy number already owned by another invoice: '.count($skippedCollision));

        if ($skippedCollision !== []) {
            $this->line(implode(', ', array_slice($skippedCollision, 0, 50)));
        }

        return self::SUCCESS;
    }
}
