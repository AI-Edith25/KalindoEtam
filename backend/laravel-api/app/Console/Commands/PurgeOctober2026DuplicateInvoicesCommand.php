<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\CreditNoteService;
use App\Services\JournalEntryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time cleanup (2026-10-21, user-confirmed):
 *
 * - TR/KE/02796/10/2026 and TR/KE/02805/10/2026 are plain Drafts — no AR, no stock, no CN/DN/JE.
 *   Same as any other draft Invoice delete, just scripted here for one combined run.
 *
 * - SI/KE/03373/10/2026 is a manually-entered duplicate of the already-imported
 *   SI/KE/08890/10/2026 (Customer Outstanding Bills Archive import — never posts its own GL entry,
 *   see Invoice::submit()'s own comment). Unlike the Drafts above, SI/KE/03373 is Submitted and
 *   already posted a real Journal Entry (GJ-36770, AR/Revenue/Tax) — user confirmed: reverse that
 *   entry first (JournalEntryService::reverse() — a Journal Entry can never be cancelled, only
 *   reversed, see JournalEntry::cancel()) so the GL keeps a clean audit trail, then delete its
 *   attached draft Credit Note (CN-00002, never submitted, zero GL impact) via
 *   CreditNoteService::delete(), then the AR row and the invoice itself. No stock to reverse —
 *   this invoice is delivery-based (Invoice::movesStock() is false; the Delivery already moved
 *   stock independently and is not touched here).
 *
 * Dry-run by default; --commit executes. Each document in its own savepoint so one failure
 * doesn't abort the rest.
 */
class PurgeOctober2026DuplicateInvoicesCommand extends Command
{
    protected $signature = 'invoices:purge-oct2026-duplicates {--commit : Actually delete; without this flag, only reports what would happen}';

    protected $description = 'Delete TR/KE/02796, TR/KE/02805, and SI/KE/03373 (10/2026) — confirmed duplicates/unused drafts.';

    /** @var array<int, string> */
    private const DRAFT_DOCUMENT_NUMBERS = ['TR/KE/02796/10/2026', 'TR/KE/02805/10/2026'];

    private const SUBMITTED_DUPLICATE_DOCUMENT_NUMBER = 'SI/KE/03373/10/2026';

    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected CreditNoteService $creditNoteService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $results = [];

        foreach (self::DRAFT_DOCUMENT_NUMBERS as $docNumber) {
            $results[] = $this->purgeDraft($docNumber, $commit);
        }

        $results[] = $this->purgeSubmittedDuplicate(self::SUBMITTED_DUPLICATE_DOCUMENT_NUMBER, $commit);

        foreach ($results as $result) {
            $this->line($result);
        }

        if (! $commit) {
            $this->line('');
            $this->warn('Dry run — no changes were made. Pass --commit to actually delete.');
        }

        return self::SUCCESS;
    }

    private function purgeDraft(string $docNumber, bool $commit): string
    {
        $invoice = Invoice::query()->where('document_number', $docNumber)->first();

        if ($invoice === null) {
            return "{$docNumber}: NOT FOUND — skipped.";
        }

        if ($invoice->status !== DocumentStatus::DRAFT) {
            return "{$docNumber}: status is {$invoice->status->value}, not draft — refusing (expected a plain Draft, re-inspect before retrying).";
        }

        if (! $commit) {
            return "{$docNumber}: would delete (draft, no AR/stock/CN/DN/JE).";
        }

        try {
            DB::transaction(function () use ($invoice) {
                $invoice->items()->delete();
                $invoice->delete();
            });

            return "{$docNumber}: deleted.";
        } catch (Throwable $e) {
            return "{$docNumber}: FAILED — {$e->getMessage()}";
        }
    }

    private function purgeSubmittedDuplicate(string $docNumber, bool $commit): string
    {
        $invoice = Invoice::query()->where('document_number', $docNumber)->first();

        if ($invoice === null) {
            return "{$docNumber}: NOT FOUND — skipped.";
        }

        $journalEntry = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->first();
        $creditNote = CreditNote::query()->where('invoice_id', $invoice->id)->first();

        if ($creditNote !== null && $creditNote->status !== DocumentStatus::DRAFT) {
            return "{$docNumber}: has a non-draft Credit Note ({$creditNote->document_number}, status={$creditNote->status->value}) — refusing, needs manual review.";
        }

        if (! $commit) {
            $steps = [];
            if ($journalEntry !== null) {
                $steps[] = "reverse {$journalEntry->document_number}";
            }
            if ($creditNote !== null) {
                $steps[] = "delete draft Credit Note {$creditNote->document_number}";
            }
            $steps[] = 'delete AR row + invoice';

            return "{$docNumber}: would ".implode(', then ', $steps).'.';
        }

        try {
            DB::transaction(function () use ($invoice, $journalEntry, $creditNote) {
                if ($journalEntry !== null) {
                    $this->journalEntryService->reverse($journalEntry);
                }

                if ($creditNote !== null) {
                    $this->creditNoteService->delete($creditNote);
                }

                $invoice->accountsReceivable()->delete();
                $invoice->items()->delete();
                $invoice->delete();
            });

            return "{$docNumber}: deleted (Journal Entry reversed, draft Credit Note removed).";
        } catch (Throwable $e) {
            return "{$docNumber}: FAILED — {$e->getMessage()}";
        }
    }
}
