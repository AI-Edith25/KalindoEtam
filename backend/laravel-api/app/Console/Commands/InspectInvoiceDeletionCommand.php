<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Illuminate\Console\Command;

/**
 * Read-only inspection for a one-off deletion request (2026-10-21): report everything that
 * determines HOW TR/KE/02796/10/2026, TR/KE/02805/10/2026 and SI/KE/03373/10/2026 must be removed
 * before any delete command is written — invoice_type, import status, whether it actually moved
 * stock (Invoice::movesStock()), AR/payment state, and anything that would block a delete outright
 * (Credit/Debit Note, a real posted Journal Entry). Never mutates anything.
 */
class InspectInvoiceDeletionCommand extends Command
{
    protected $signature = 'invoices:inspect-deletion {documentNumbers*}';

    protected $description = 'Report the current state of specific invoices to determine safe deletion handling.';

    public function handle(): int
    {
        $docNumbers = $this->argument('documentNumbers');
        $invoices = Invoice::query()->with(['accountsReceivable', 'delivery', 'customer'])
            ->whereIn('document_number', $docNumbers)->get()->keyBy('document_number');

        foreach ($docNumbers as $docNumber) {
            $invoice = $invoices->get($docNumber);

            if ($invoice === null) {
                $this->warn("{$docNumber}: NOT FOUND");
                $this->line('');

                continue;
            }

            $invoice->load('items');
            $ar = $invoice->accountsReceivable;

            $this->info($docNumber);
            $this->line('  id: '.$invoice->id);
            $this->line('  customer: '.($invoice->customer?->customer_name ?? '—'));
            $this->line('  invoice_type: '.$invoice->invoice_type->value);
            $this->line('  status: '.$invoice->status->value);
            $this->line('  import_source_type: '.($invoice->import_source_type ?? '— (manual entry)'));
            $this->line('  affects_stock flag: '.($invoice->affects_stock ? 'true' : 'false'));
            $this->line('  delivery_id (delivery-based?): '.($invoice->delivery_id ?? '— (no Delivery)'));
            $this->line('  isDirectGoods(): '.($invoice->isDirectGoods() ? 'true' : 'false'));
            $this->line('  movesStock() -> stock WILL be reversed if deleted: '.($invoice->movesStock() ? 'YES' : 'no'));
            $this->line('  item lines: '.$invoice->items->count().' (with item_id / stock-affecting: '.$invoice->items->whereNotNull('item_id')->count().')');
            $this->line('  AR row: '.($ar ? "paid_amount={$ar->paid_amount}, outstanding={$ar->outstanding_amount}" : '— none'));
            $this->line('  Credit Note against it: '.(CreditNote::query()->where('invoice_id', $invoice->id)->exists() ? 'YES — blocks delete' : 'no'));
            $this->line('  Debit Note against it: '.(DebitNote::query()->where('invoice_id', $invoice->id)->exists() ? 'YES — blocks delete' : 'no'));
            $this->line('  Real posted Journal Entry: '.(JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->exists() ? 'YES — blocks delete' : 'no'));
            $this->line('');
        }

        return self::SUCCESS;
    }
}
