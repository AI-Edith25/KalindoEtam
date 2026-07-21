<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\JournalEntry;
use App\Repositories\JournalEntryLineRepository;
use App\Repositories\JournalEntryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The actual Journal Entry engine — validation, posting, reversal. Used
 * directly by the manual "Journal Posting" UI, and internally by
 * AccountingService on behalf of business modules (Invoice, Receipt
 * Entry, ...). One creation/validation path either way — which is also why
 * gating PeriodLockService here (create/update/post) is the single
 * enforcement point for Period Closing across the whole app; no other
 * service duplicates the check. See docs/PERIOD_CLOSING_DESIGN.md §1.
 */
class JournalEntryService
{
    protected const EAGER = ['lines.chartOfAccount', 'lines.branch', 'referenceDocument', 'reverses', 'reversedBy', 'creator'];

    public function __construct(
        protected JournalEntryRepository $journalEntryRepository,
        protected JournalEntryLineRepository $journalEntryLineRepository,
        protected PeriodLockService $periodLockService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->journalEntryRepository->search($filters, $perPage);
    }

    public function create(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data) {
            $this->periodLockService->assertOpen($data['posting_date']);

            $lines = $data['lines'];
            $this->assertHasEnoughLines($lines);
            $this->assertEachLineIsSingleSided($lines);
            [$totalDebit, $totalCredit] = $this->sumLines($lines);
            $this->assertBalanced($totalDebit, $totalCredit);

            $journalEntry = $this->journalEntryRepository->create([
                'posting_date' => $data['posting_date'],
                'description' => $data['description'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'reverses_id' => $data['reverses_id'] ?? null,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            $this->replaceLines($journalEntry, $lines);

            $journalEntry = $journalEntry->fresh(self::EAGER);
            $this->auditLogService->record('created', 'journal_entry', "Created Journal Entry \"{$journalEntry->document_number}\".");

            return $journalEntry;
        });
    }

    public function update(JournalEntry $journalEntry, array $data): JournalEntry
    {
        return DB::transaction(function () use ($journalEntry, $data) {
            $this->assertDraft($journalEntry, 'updated');

            if (isset($data['posting_date'])) {
                $this->periodLockService->assertOpen($data['posting_date']);
            }

            $headerData = [
                'posting_date' => $data['posting_date'] ?? $journalEntry->posting_date,
                'description' => $data['description'] ?? $journalEntry->description,
            ];

            if (isset($data['lines'])) {
                $lines = $data['lines'];
                $this->assertHasEnoughLines($lines);
                $this->assertEachLineIsSingleSided($lines);
                [$totalDebit, $totalCredit] = $this->sumLines($lines);
                $this->assertBalanced($totalDebit, $totalCredit);

                $journalEntry->lines()->delete();
                $this->replaceLines($journalEntry, $lines);

                $headerData['total_debit'] = $totalDebit;
                $headerData['total_credit'] = $totalCredit;
            }

            $this->journalEntryRepository->update($journalEntry, $headerData);

            $journalEntry = $journalEntry->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'journal_entry', "Updated Journal Entry \"{$journalEntry->document_number}\".");

            return $journalEntry;
        });
    }

    public function delete(JournalEntry $journalEntry): void
    {
        DB::transaction(function () use ($journalEntry) {
            $this->assertDraft($journalEntry, 'deleted');
            $documentNumber = $journalEntry->document_number;
            $this->journalEntryRepository->delete($journalEntry);
            $this->auditLogService->record('deleted', 'journal_entry', "Deleted Journal Entry \"{$documentNumber}\".");
        });
    }

    /** Posting is Documentable's ordinary Draft -> Submitted transition — see JournalEntry's own docblock for why. */
    public function post(JournalEntry $journalEntry): JournalEntry
    {
        return DB::transaction(function () use ($journalEntry) {
            // The load-bearing check: this is the only moment an entry starts affecting any
            // report (only SUBMITTED entries are ever read) — a period could have closed between
            // create() and post(), so create()'s own check alone is not sufficient.
            $this->periodLockService->assertOpen($journalEntry->posting_date->toDateString());

            $journalEntry->submit();

            $journalEntry = $journalEntry->fresh(self::EAGER);
            $this->auditLogService->record('posted', 'journal_entry', "Posted Journal Entry \"{$journalEntry->document_number}\".");

            return $journalEntry;
        });
    }

    /**
     * Corrects a posted entry by posting a new one with every line's
     * debit/credit swapped — the original is never touched beyond linking
     * to its reversal, preserving the audit trail.
     */
    public function reverse(JournalEntry $journalEntry): JournalEntry
    {
        return DB::transaction(function () use ($journalEntry) {
            if ($journalEntry->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException('Only a posted Journal Entry can be reversed.');
            }

            if ($journalEntry->reversed_by_id !== null) {
                throw new BusinessException('This Journal Entry has already been reversed.');
            }

            $journalEntry->load('lines');

            $reversal = $this->create([
                'posting_date' => now()->toDateString(),
                'description' => "Reversal of {$journalEntry->document_number}",
                'reference_type' => $journalEntry->reference_type,
                'reference_id' => $journalEntry->reference_id,
                'reverses_id' => $journalEntry->id,
                'lines' => $journalEntry->lines->map(fn ($line) => [
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'branch_id' => $line->branch_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'description' => $line->description,
                ])->all(),
            ]);

            $reversal = $this->post($reversal);

            $this->journalEntryRepository->update($journalEntry, ['reversed_by_id' => $reversal->id]);

            $this->auditLogService->record('reversed', 'journal_entry', "Reversed Journal Entry \"{$journalEntry->document_number}\" via \"{$reversal->document_number}\".");

            return $reversal;
        });
    }

    protected function replaceLines(JournalEntry $journalEntry, array $lines): void
    {
        foreach ($lines as $line) {
            $this->journalEntryLineRepository->create([
                'journal_entry_id' => $journalEntry->id,
                'chart_of_account_id' => $line['chart_of_account_id'],
                'branch_id' => $line['branch_id'] ?? null,
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'description' => $line['description'] ?? null,
            ]);
        }
    }

    protected function assertHasEnoughLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new BusinessException('A Journal Entry needs at least two lines.');
        }
    }

    protected function assertEachLineIsSingleSided(array $lines): void
    {
        foreach ($lines as $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw new BusinessException('Debit and credit amounts cannot be negative.');
            }

            if (($debit > 0) === ($credit > 0)) {
                throw new BusinessException('Each Journal Entry line must have either a debit or a credit amount, not both or neither.');
            }
        }
    }

    protected function sumLines(array $lines): array
    {
        $totalDebit = array_sum(array_map(fn ($line) => (float) ($line['debit'] ?? 0), $lines));
        $totalCredit = array_sum(array_map(fn ($line) => (float) ($line['credit'] ?? 0), $lines));

        return [$totalDebit, $totalCredit];
    }

    protected function assertBalanced(float $totalDebit, float $totalCredit): void
    {
        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            throw new BusinessException("Journal Entry is not balanced: total debit ({$totalDebit}) does not equal total credit ({$totalCredit}).");
        }
    }

    protected function assertDraft(JournalEntry $journalEntry, string $action): void
    {
        if ($journalEntry->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Journal Entries can be {$action}.");
        }
    }
}
