<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentAllocation;
use App\Services\PaymentAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time cleanup (2026-10-15, user-confirmed): these ~105 Sales Invoice + Transportation Invoice
 * documents for TB. ANUGERAH JAYA SAMBUTAN are duplicates left over from the old-system-to-new-system
 * migration — the same transactions were ALSO entered manually (different document numbers, same
 * customer/amount/date), so the normal duplicate-import guard never caught them. List transcribed
 * from Perludihapus.pdf's own "DOCUMENT" column. Same shape as PurgeHistoricalSalesInvoiceImportCommand,
 * but scoped by an explicit document_number list instead of import_source_type, plus two extra safety
 * checks that command doesn't need: refuses a row whose import_source_type is null (this list is
 * user-confirmed import-only; a manually-entered row with the same number would mean the list is
 * wrong) and refuses a row with a real posted JournalEntry already (import invoices normally never
 * post one — see InvoiceService::submit()'s own comment — so if one exists here the "never posted a
 * GL entry" assumption this command relies on is false for that row).
 */
class PurgeDuplicateAnugerahJayaInvoicesCommand extends Command
{
    protected $signature = 'invoices:purge-anugerah-jaya-duplicates {--commit : Actually delete; without this flag, only reports what would happen}';

    protected $description = 'Delete the duplicate-import SI/TR invoices for TB. Anugerah Jaya Sambutan listed in Perludihapus.pdf.';

    /** @var array<int, string> */
    private const DOCUMENT_NUMBERS = [
        'SI/KE/08979/10/2026', 'SI/KE/08980/10/2026', 'SI/KE/08807/10/2026', 'TR/KE/07437/10/2026',
        'TR/KE/07455/10/2026', 'TR/KE/07430/10/2026', 'TR/KE/07427/10/2026', 'SI/KE/08783/10/2026',
        'TR/KE/07431/10/2026', 'TR/KE/07423/10/2026', 'SI/KE/08795/10/2026', 'TR/KE/07443/10/2026',
        'SI/KE/08792/10/2026', 'TR/KE/07440/10/2026', 'TR/KE/07453/10/2026', 'SI/KE/08796/10/2026',
        'TR/KE/07444/10/2026', 'TR/KE/07451/10/2026', 'TR/KE/07438/10/2026', 'SI/KE/08798/10/2026',
        'TR/KE/07446/10/2026', 'SI/KE/08794/10/2026', 'TR/KE/07442/10/2026', 'SI/KE/08788/10/2026',
        'TR/KE/07436/10/2026', 'SI/KE/08780/10/2026', 'TR/KE/07428/10/2026', 'SI/KE/08978/10/2026',
        'SI/KE/08889/10/2026', 'SI/KE/08982/10/2026',

        'SI/KE/08967/10/2026', 'TR/KE/07573/10/2026', 'SI/KE/08861/10/2026', 'TR/KE/07500/10/2026',
        'SI/KE/08854/10/2026', 'TR/KE/07506/10/2026', 'SI/KE/08922/10/2026', 'TR/KE/07540/10/2026',
        'SI/KE/08921/10/2026', 'TR/KE/07539/10/2026', 'SI/KE/08925/10/2026', 'SI/KE/08923/10/2026',
        'SI/KE/08865/10/2026', 'TR/KE/07511/10/2026', 'SI/KE/08875/10/2026', 'SI/KE/08876/10/2026',
        'SI/KE/08878/10/2026', 'SI/KE/08879/10/2026', 'SI/KE/08874/10/2026', 'SI/KE/08872/10/2026',
        'SI/KE/08873/10/2026', 'SI/KE/08869/10/2026', 'SI/KE/08870/10/2026', 'SI/KE/08868/10/2026',
        'SI/KE/08871/10/2026', 'SI/KE/08972/10/2026', 'TR/KE/07578/10/2026', 'SI/KE/08858/10/2026',
        'TR/KE/07510/10/2026', 'SI/KE/08785/10/2026', 'TR/KE/07433/10/2026', 'SI/KE/08970/10/2026',
        'TR/KE/07576/10/2026', 'SI/KE/08976/10/2026', 'SI/KE/08888/10/2026', 'SI/KE/08968/10/2026',
        'TR/KE/07574/10/2026', 'SI/KE/08973/10/2026',

        'TR/KE/07579/10/2026', 'SI/KE/08974/10/2026', 'TR/KE/07580/10/2026', 'SI/KE/08977/10/2026',
        'SI/KE/08969/10/2026', 'SI/KE/08971/10/2026', 'TR/KE/07577/10/2026', 'SI/KE/08862/10/2026',
        'TR/KE/07501/10/2026', 'SI/KE/08863/10/2026', 'TR/KE/07502/10/2026', 'SI/KE/08857/10/2026',
        'TR/KE/07509/10/2026', 'SI/KE/08801/10/2026', 'TR/KE/07449/10/2026', 'SI/KE/08793/10/2026',
        'TR/KE/07441/10/2026', 'TR/KE/07450/10/2026', 'SI/KE/08803/10/2026', 'SI/KE/08804/10/2026',
        'SI/KE/08855/10/2026', 'TR/KE/07507/10/2026', 'SI/KE/08791/10/2026', 'SI/KE/08784/10/2026',
        'TR/KE/07432/10/2026', 'SI/KE/08777/10/2026', 'TR/KE/07425/10/2026', 'SI/KE/08778/10/2026',
        'TR/KE/07426/10/2026', 'SI/KE/08787/10/2026', 'TR/KE/07435/10/2026', 'TR/KE/07429/10/2026',
        'SI/KE/08772/10/2026', 'TR/KE/07420/10/2026', 'SI/KE/08864/10/2026', 'TR/KE/07503/10/2026',
        'TR/KE/07452/10/2026',
    ];

    public function __construct(protected PaymentAllocationService $paymentAllocationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $docNumbers = array_unique(self::DOCUMENT_NUMBERS);
        $invoices = Invoice::query()->whereIn('document_number', $docNumbers)->get()->keyBy('document_number');

        $missing = array_values(array_diff($docNumbers, $invoices->keys()->all()));
        $deleted = 0;
        $blocked = [];

        $bar = $this->output->createProgressBar($invoices->count());
        $bar->start();

        foreach ($invoices as $invoice) {
            $reason = $this->blockingReason($invoice);

            if ($reason !== null) {
                $blocked[] = "{$invoice->document_number}: {$reason}";
                $bar->advance();

                continue;
            }

            if (! $this->option('commit')) {
                $deleted++;
                $bar->advance();

                continue;
            }

            try {
                DB::transaction(function () use ($invoice) {
                    $this->reverseAllocations($invoice);
                    $invoice->items()->delete();
                    $invoice->accountsReceivable()->delete();
                    $invoice->delete();
                });
                $deleted++;
            } catch (Throwable $e) {
                $blocked[] = "{$invoice->document_number}: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        if (! $this->option('commit')) {
            $this->warn('Dry run — no changes were made. Pass --commit to actually delete.');
        }

        $this->info(($this->option('commit') ? 'Invoices deleted: ' : 'Invoices that would be deleted: ').$deleted);

        if ($missing !== []) {
            $this->warn('Document numbers not found in the database (already gone, or a transcription mismatch — verify against Perludihapus.pdf):');
            foreach ($missing as $docNumber) {
                $this->line("  - {$docNumber}");
            }
        }

        if ($blocked !== []) {
            $this->warn('Invoices skipped (resolve manually, then re-run):');
            foreach ($blocked as $reason) {
                $this->line("  - {$reason}");
            }
        }

        return self::SUCCESS;
    }

    private function blockingReason(Invoice $invoice): ?string
    {
        if (! str_contains(mb_strtoupper((string) $invoice->customer?->customer_name), 'ANUGERAH JAYA')) {
            return 'customer is "'.($invoice->customer?->customer_name ?? '—').'", not Anugerah Jaya — list may be wrong, skipping.';
        }

        if ($invoice->import_source_type === null) {
            return 'import_source_type is null (not an imported row) — refusing to delete a manually-entered invoice.';
        }

        if (CreditNote::query()->where('invoice_id', $invoice->id)->exists()) {
            return 'has a Credit Note issued against it.';
        }

        if (DebitNote::query()->where('invoice_id', $invoice->id)->exists()) {
            return 'has a Debit Note issued against it.';
        }

        if (JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->exists()) {
            return 'has a real posted Journal Entry against it — not the usual import-skips-GL case, needs manual review.';
        }

        return null;
    }

    /** Same as PurgeHistoricalSalesInvoiceImportCommand::reverseAllocations() — reverses every
        non-reversed PaymentAllocation against this invoice's AR, leaving the Official Receipt
        itself untouched. No-op for an invoice with no AR row. */
    private function reverseAllocations(Invoice $invoice): void
    {
        $arId = $invoice->accountsReceivable?->id;

        if ($arId === null) {
            return;
        }

        PaymentAllocation::query()->where('accounts_receivable_id', $arId)->where('is_reversed', false)
            ->get()->each(fn (PaymentAllocation $allocation) => $this->paymentAllocationService->reverse($allocation));
    }
}
