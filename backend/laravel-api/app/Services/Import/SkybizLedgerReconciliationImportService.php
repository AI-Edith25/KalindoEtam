<?php

namespace App\Services\Import;

use App\Enums\DocumentStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\PaymentMethod;
use App\Models\AccountsReceivable;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\ReceiptEntry;
use App\Services\Import\Concerns\ParsesLegacyLedgerExport;
use App\Services\PaymentAllocationService;
use App\Services\ReceiptEntryService;
use App\Support\DocumentKeyNormalizer;
use App\Support\ImportErrorReportWriter;
use App\Support\SkybizDocumentKeyNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Replays Skybiz's "Customer Ledger" (Debtors Ledger) export against KE's
 * own invoices to recover payment history that was never migrated in
 * January 2026 (16,284 accounts_receivables rows, only ~192 with
 * paid_amount > 0 — see prompt-import-pelunasan-skybiz.md). One service,
 * two modes sharing the exact same parse/match/plan step (buildPlan()) so
 * preview and commit can never disagree, same philosophy as
 * ImportBatchService::preview()/commit().
 *
 * File shape is a per-customer running-balance ledger (B/F, SJ, CB, GJ
 * TranTypes), NOT the DOCUMENT#-grouped double-entry export
 * ParsesLegacyLedgerExport/OfficialReceiptImportService was built for — only
 * that trait's direction-agnostic column-mapping/date-parsing/fuzzy-name
 * primitives are reused here; the grouping and allocation logic below is new.
 *
 * GJ rows (non-cash adjustments) are allocated exactly like CB rows but
 * always posted against the dedicated suspense account (code 1199, see its
 * migration) instead of a fuzzy-matched real bank — CreditNoteService
 * rejects Transportation invoices outright, so it can't uniformly cover
 * GJ's SI+TR mix the way this can. See the ticket's "Key decision: GJ rows".
 */
final class SkybizLedgerReconciliationImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 1.0;

    private const SUSPENSE_ACCOUNT_CODE = '1199';

    public function __construct(
        protected ReceiptEntryService $receiptEntryService,
        protected PaymentAllocationService $paymentAllocationService,
    ) {}

    public function run(ImportBatch $batch, bool $commit): void
    {
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        $fields = $this->skybizFieldDefinitions();
        $headerSettings = HeaderDetector::detect($rawRows, $fields);
        $headerRow = $rawRows[$headerSettings['header_row'] - 1] ?? [];
        $columnIndex = $this->mapColumns($headerRow, $fields);

        $requiredLabels = ['date' => 'Date', 'inv_chq' => 'Inv/Chq #', 'tran_type' => 'TranType', 'debit' => 'Debit', 'credit' => 'Credit'];
        $missing = array_values(array_diff_key($requiredLabels, $columnIndex));

        if ($missing !== []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Kolom wajib tidak terdeteksi di file ini: '.implode(', ', $missing).'.',
            ]);

            return;
        }

        $dataRows = array_slice($rawRows, $headerSettings['data_start_row'] - 1);
        $blocks = $this->groupCustomerBlocks($dataRows, $columnIndex);

        if ($blocks === []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Tidak ada baris data yang bisa dibaca dari file ini.',
            ]);

            return;
        }

        $batch->update(['total_rows' => count($blocks), 'status' => ImportBatchStatus::PROCESSING, 'started_at' => now()]);

        $plan = $this->buildPlan($blocks, $batch);
        $batch->update(['processed_rows' => count($blocks)]);

        $successCount = 0;
        $reportRows = $plan['report_rows'];

        if ($commit) {
            $suspenseAccount = ChartOfAccount::query()->where('code', self::SUSPENSE_ACCOUNT_CODE)->firstOrFail();
            $cashAccounts = ChartOfAccount::query()->where('is_cash_bank', true)->where('is_active', true)
                ->where('code', '!=', self::SUSPENSE_ACCOUNT_CODE)->get();

            foreach ($plan['or_groups'] as $group) {
                $outcomes = $this->writeGroup($group, $cashAccounts, $suspenseAccount, $batch);
                foreach ($outcomes as $outcome) {
                    $reportRows[] = $outcome;
                    if ($outcome['status'] === 'success') {
                        $successCount++;
                    }
                }
            }
        }

        $batch->update([
            'success_rows' => $successCount,
            'failed_rows' => count(array_filter($reportRows, fn ($r) => $r['status'] === 'failed')),
            'preview_summary' => $plan['summary'],
            'status' => $commit ? ImportBatchStatus::COMPLETED : ImportBatchStatus::PREVIEWED,
        ]);

        // Also written at preview time (not just after commit) — every one of these rows is
        // 'needs_review', never 'success'/'failed' until commit runs, so this is exactly the
        // ticket's "preview_sample ... untuk spot-check manusia" in full, downloadable before
        // anyone presses "Jalankan Import".
        ImportErrorReportWriter::attachRejectedRows($batch, $reportRows);
    }

    /** @return ImportFieldDefinition[] */
    private function skybizFieldDefinitions(): array
    {
        return [
            new ImportFieldDefinition('date', 'Date', 'date', required: true),
            new ImportFieldDefinition('particulars', 'Particulars', 'string'),
            new ImportFieldDefinition('inv_chq', 'Inv/Chq #', 'string'),
            new ImportFieldDefinition('tran_type', 'TranType', 'string', required: true),
            new ImportFieldDefinition('batch_no', 'Batch #', 'string'),
            new ImportFieldDefinition('debit', 'Debit', 'number', required: true),
            new ImportFieldDefinition('credit', 'Credit', 'number', required: true),
            new ImportFieldDefinition('balance', 'Balance', 'number'),
        ];
    }

    /**
     * Splits the raw data rows into per-customer blocks (ticket §1). A block-header row has
     * only the Date column populated, with text matching "<CODE> - <Name>"; everything else
     * (TranType blank + Debit/Credit both zero) is structural. A subtotal row is the opposite
     * shape — TranType blank but Debit/Credit numeric — and is skipped, never a new block.
     *
     * @return array<int, array{label: string, rows: array}>
     */
    private function groupCustomerBlocks(array $dataRows, array $columnIndex): array
    {
        $decimalValues = [];
        foreach (['debit', 'credit'] as $field) {
            foreach ($dataRows as $row) {
                $decimalValues[] = $row[$columnIndex[$field]] ?? null;
            }
        }
        $decimalStyle = DataCleaner::detectDecimalStyle($decimalValues);

        $blocks = [];
        $current = null;

        foreach ($dataRows as $raw) {
            $get = fn (string $field) => isset($columnIndex[$field]) ? ($raw[$columnIndex[$field]] ?? null) : null;

            $dateCell = $get('date');
            $tranType = DataCleaner::normalizeText($this->toStringOrNull($get('tran_type')));
            $debit = DataCleaner::normalizeNumber($get('debit'), $decimalStyle) ?? 0.0;
            $credit = DataCleaner::normalizeNumber($get('credit'), $decimalStyle) ?? 0.0;

            $looksLikeHeader = $tranType === null
                && $debit < self::AMOUNT_EPSILON
                && $credit < self::AMOUNT_EPSILON
                && is_string($dateCell)
                && trim($dateCell) !== ''
                && $this->parseLedgerDate($dateCell) === null
                && preg_match('/^(.+?)\s*-\s*(.+)$/', trim($dateCell), $m) === 1;

            if ($looksLikeHeader) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = ['label' => trim($dateCell), 'code' => trim($m[1]), 'name' => trim($m[2]), 'rows' => []];

                continue;
            }

            // Subtotal row (TranType blank, an amount present) or a blank separator — neither is
            // a transaction.
            if ($tranType === null || $tranType === 'B/F') {
                continue;
            }

            if ($current === null) {
                continue; // defensive: a transaction row before any block header can't happen in a real export
            }

            $current['rows'][] = [
                'tran_type' => $tranType,
                'date' => $this->parseLedgerDate($dateCell),
                'inv_chq' => DataCleaner::normalizeText($this->toStringOrNull($get('inv_chq'))),
                'particulars' => DataCleaner::normalizeText($this->toStringOrNull($get('particulars'))),
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Shared by preview and commit — runs the per-customer FIFO/explicit-match allocation
     * (ticket §3), matches every resulting ledger invoice against KE (ticket §4), and groups the
     * correction amounts that still need posting into "Official Receipt" write groups (ticket §5),
     * without writing anything. Commit mode reuses this exact plan for the real writes, so the two
     * can never disagree about what needs to happen.
     *
     * @return array{summary: array, or_groups: array, report_rows: array}
     */
    private function buildPlan(array $blocks, ImportBatch $batch): array
    {
        [$strictIndex, $looseIndex, $arByInvoiceId] = $this->buildInvoiceIndex();
        $earliestKeDate = Invoice::query()->min('invoice_date');

        $summary = [
            'customers_processed' => count($blocks),
            'invoices_matched_up_to_date' => 0,
            'invoices_to_correct' => 0,
            'total_amount_to_apply' => 0.0,
            'or_to_create' => 0,
            'unmatched_unparseable_ref' => 0,
            'unmatched_ambiguous' => 0,
            'unmatched_not_found_in_scope' => 0,
            'payment_before_invoice' => 0,
            'ke_higher_than_skybiz_conflict' => 0,
            'reversal_rows_needs_review' => 0,
            'pre_migration_out_of_scope' => 0,
            'invoice_cancelled_skipped' => 0,
            'already_imported' => 0,
        ];

        $reportRows = [];
        $orGroups = [];

        // Skybiz block codes ("C-0229") are the same codes KE stores on Customer.customer_code.
        $customerIdByCode = Customer::query()->pluck('id', 'customer_code')->all();

        foreach ($blocks as $blockIndex => $block) {
            // The header regex in groupCustomerBlocks() splits at the first hyphen, so 'code' is only
            // "C" for "C-0229 - …" — the real code is the label's part before " - ".
            $blockCustomerId = $customerIdByCode[trim(explode(' - ', $block['label'], 2)[0])] ?? null;
            [$ledgerInvoices, $reversalRows] = $this->allocateBlock($block);

            foreach ($reversalRows as $row) {
                $summary['reversal_rows_needs_review']++;
                $reportRows[] = $row;
            }

            foreach ($ledgerInvoices as $inv) {
                $this->matchAndPlanInvoice(
                    $inv, $blockIndex, $block, $blockCustomerId, $strictIndex, $looseIndex, $arByInvoiceId, $earliestKeDate,
                    $summary, $reportRows, $orGroups,
                );
            }
        }

        // Idempotency (ticket §5/§4): drop any group whose Skybiz reference was already imported —
        // computed here (not just at write time) so preview's or_to_create can never overstate what
        // commit will actually do.
        foreach ($orGroups as $key => $group) {
            $normalizedRef = DocumentKeyNormalizer::normalize($group['meta']['ref']);
            if ($normalizedRef !== null && ReceiptEntry::query()->where('reference_number_normalized', $normalizedRef)->exists()) {
                $summary['already_imported']++;
                $reportRows[] = [
                    'category' => 'already_imported', 'status' => 'needs_review',
                    'reference' => $group['meta']['ref'], 'customer_block' => $group['meta']['block_label'],
                    'document_ref' => null, 'amount' => null, 'date' => $group['meta']['date'],
                    'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.',
                ];
                unset($orGroups[$key]);
            }
        }

        $summary['or_to_create'] = count($orGroups);
        $summary['preview_sample'] = $this->sampleReportRows($reportRows);

        return ['summary' => $summary, 'or_groups' => array_values($orGroups), 'report_rows' => $reportRows];
    }

    /** Up to 3 example rows per distinct category, for the preview's human spot-check sample. */
    private function sampleReportRows(array $reportRows): array
    {
        $byCategory = [];
        foreach ($reportRows as $row) {
            $byCategory[$row['category']][] = $row;
        }

        $sample = [];
        foreach ($byCategory as $rows) {
            array_push($sample, ...array_slice($rows, 0, 3));
        }

        return $sample;
    }

    /**
     * The FIFO + explicit-match state machine (ticket §3), scoped to one customer block so
     * old-format sequence-only refs from different customers can never collide. Returns every SJ
     * row as a ledger "invoice" object (amount, cumulative paid, chronological list of applying
     * events) plus any GJ/CB Debit>0 reversal rows found in this block.
     *
     * @return array{0: list<object>, 1: array}
     */
    private function allocateBlock(array $block): array
    {
        $openInvoices = [];
        $allInvoices = [];
        $reversalRows = [];

        foreach ($block['rows'] as $row) {
            if ($row['tran_type'] === 'SJ') {
                $inv = (object) [
                    'raw_ref' => $row['inv_chq'],
                    'normalized' => SkybizDocumentKeyNormalizer::normalize($row['inv_chq']),
                    'amount' => $row['debit'],
                    'paid' => 0.0,
                    'date' => $row['date'],
                    'events' => [],
                ];
                $openInvoices[] = $inv;
                $allInvoices[] = $inv;

                continue;
            }

            if ($row['debit'] > self::AMOUNT_EPSILON) {
                // GJ/CB with Debit > 0 — reversal/retur, never auto-applied (ticket §3 bullet 4).
                $reversalRows[] = [
                    'category' => 'reversal_rows_needs_review', 'status' => 'needs_review',
                    'reference' => $row['inv_chq'], 'customer_block' => $block['label'],
                    'document_ref' => null, 'amount' => $row['debit'], 'date' => $row['date'],
                    'reason' => "Baris {$row['tran_type']} dengan Debit > 0 (kemungkinan reversal/retur) — perlu cek manual.",
                ];

                continue;
            }

            $remaining = $row['credit'];

            if ($remaining <= self::AMOUNT_EPSILON) {
                continue;
            }

            $particularsUpper = strtoupper($row['particulars'] ?? '');

            // Explicit match pass: a row's Particulars may name one or more open invoices by
            // their own raw reference text (ticket §3 bullet 1).
            foreach ($openInvoices as $inv) {
                if ($remaining <= self::AMOUNT_EPSILON) {
                    break;
                }

                $outstanding = $inv->amount - $inv->paid;
                if ($outstanding <= self::AMOUNT_EPSILON || ($inv->raw_ref ?? '') === '') {
                    continue;
                }

                if (! str_contains($particularsUpper, strtoupper($inv->raw_ref))) {
                    continue;
                }

                $apply = min($remaining, $outstanding);
                $inv->paid += $apply;
                $inv->events[] = ['ref' => $row['inv_chq'], 'type' => $row['tran_type'], 'date' => $row['date'], 'amount' => $apply, 'particulars' => $row['particulars']];
                $remaining -= $apply;
            }

            // FIFO pass for whatever's left, oldest SJ first (ticket §3 bullet 2).
            if ($remaining > self::AMOUNT_EPSILON) {
                $fifoOrder = $openInvoices;
                usort($fifoOrder, fn ($a, $b) => strcmp($a->date ?? '', $b->date ?? ''));

                foreach ($fifoOrder as $inv) {
                    if ($remaining <= self::AMOUNT_EPSILON) {
                        break;
                    }

                    $outstanding = $inv->amount - $inv->paid;
                    if ($outstanding <= self::AMOUNT_EPSILON) {
                        continue;
                    }

                    $apply = min($remaining, $outstanding);
                    $inv->paid += $apply;
                    $inv->events[] = ['ref' => $row['inv_chq'], 'type' => $row['tran_type'], 'date' => $row['date'], 'amount' => $apply, 'particulars' => $row['particulars']];
                    $remaining -= $apply;
                }
            }

            // Drop invoices fully settled by this row from the OPEN list (allInvoices keeps every
            // invoice regardless) — a credit row always re-scans every still-open invoice, so for a
            // customer block with thousands of rows, leaving fully-paid ones in makes both the
            // explicit-match pass and the per-row usort() in the FIFO pass grow unbounded over the
            // block's whole history instead of staying proportional to what's actually outstanding.
            $openInvoices = array_values(array_filter(
                $openInvoices,
                fn ($inv) => ($inv->amount - $inv->paid) > self::AMOUNT_EPSILON,
            ));
        }

        return [$allInvoices, $reversalRows];
    }

    /**
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>, 2: Collection}
     */
    private function buildInvoiceIndex(): array
    {
        $receivables = AccountsReceivable::query()->whereNotNull('invoice_id')->with('invoice')->get();
        $arByInvoiceId = $receivables->keyBy('invoice_id');

        $strictIndex = [];
        $looseIndex = [];

        foreach ($receivables as $ar) {
            $invoice = $ar->invoice;
            if ($invoice === null) {
                continue;
            }

            $normalized = SkybizDocumentKeyNormalizer::normalize($invoice->document_number);
            if ($normalized === null) {
                continue;
            }

            if ($normalized['strict_key'] !== null) {
                $strictIndex[$normalized['strict_key']][] = $invoice->id;
            }
            $looseIndex[$normalized['loose_key']][] = $invoice->id;
        }

        return [$strictIndex, $looseIndex, $arByInvoiceId];
    }

    /**
     * Only invoices owned by the same KE customer as the Skybiz block are candidates — a Skybiz
     * "TR-KE-06933-09-2024" from one customer must never resolve to another customer's 2026
     * invoice that happens to share the sequence number. And a ref that carries month+year never
     * falls back to the loose type+seq key when the strict key finds nothing for this customer.
     *
     * @return array{0: ?string, 1: string} invoice id (or null) and match status
     */
    private function resolveKeInvoiceId(array $normalized, array $strictIndex, array $looseIndex, ?string $blockCustomerId, Collection $arByInvoiceId): array
    {
        $sameCustomer = fn (array $ids) => array_values(array_filter(
            array_unique($ids),
            fn ($id) => $blockCustomerId !== null && $arByInvoiceId->get($id)?->customer_id === $blockCustomerId,
        ));

        if ($normalized['strict_key'] !== null) {
            $candidates = $sameCustomer($strictIndex[$normalized['strict_key']] ?? []);

            return match (count($candidates)) {
                1 => [$candidates[0], 'matched'],
                0 => [null, 'not_found'],
                default => [null, 'ambiguous'],
            };
        }

        $looseCandidates = $sameCustomer($looseIndex[$normalized['loose_key']] ?? []);

        return match (count($looseCandidates)) {
            1 => [$looseCandidates[0], 'matched'],
            0 => [null, 'not_found'],
            default => [null, 'ambiguous'],
        };
    }

    /** Matches one ledger invoice to KE (ticket §4) and, if a real correction is owed, splits it chronologically across its applying events into $orGroups (ticket §5's grouping, built here so commit just replays it). */
    private function matchAndPlanInvoice(
        object $inv,
        int $blockIndex,
        array $block,
        ?string $blockCustomerId,
        array $strictIndex,
        array $looseIndex,
        Collection $arByInvoiceId,
        ?string $earliestKeDate,
        array &$summary,
        array &$reportRows,
        array &$orGroups,
    ): void {
        if ($inv->normalized === null) {
            $summary['unmatched_unparseable_ref']++;
            $reportRows[] = $this->reportRow('unmatched_unparseable_ref', $inv, $block, 'Format referensi tidak bisa dibaca (bukan SI/TR, atau tanpa nomor urut).');

            return;
        }

        [$keInvoiceId, $status] = $this->resolveKeInvoiceId($inv->normalized, $strictIndex, $looseIndex, $blockCustomerId, $arByInvoiceId);

        if ($status === 'ambiguous') {
            $summary['unmatched_ambiguous']++;
            $reportRows[] = $this->reportRow('unmatched_ambiguous', $inv, $block, 'Nomor urut cocok dengan lebih dari satu invoice KE (tanpa bulan/tahun yang jelas) — tidak ditebak.');

            return;
        }

        if ($status === 'not_found') {
            $looksCurrent = str_contains($inv->raw_ref ?? '', '/KE/')
                || ($inv->date !== null && $earliestKeDate !== null && $inv->date >= $earliestKeDate);

            if ($looksCurrent) {
                $summary['unmatched_not_found_in_scope']++;
                $reportRows[] = $this->reportRow('unmatched_not_found_in_scope', $inv, $block, 'Ada di ledger Skybiz tapi invoice ini tidak ditemukan di KE.');
            } else {
                $summary['pre_migration_out_of_scope']++;
            }

            return;
        }

        $ar = $arByInvoiceId->get($keInvoiceId);
        if ($ar === null) {
            $summary['unmatched_not_found_in_scope']++;
            $reportRows[] = $this->reportRow('unmatched_not_found_in_scope', $inv, $block, 'Invoice ditemukan di KE tapi tidak punya baris Accounts Receivable.');

            return;
        }

        // A cancelled Invoice can still carry an AR row (e.g. backfilled historical data) but has
        // no real outstanding receivable to settle — posting a payment against it would be
        // nonsensical, found in production data (an import-backfilled, cancelled invoice).
        if ($ar->invoice?->status === DocumentStatus::CANCELLED) {
            $summary['invoice_cancelled_skipped']++;
            $reportRows[] = $this->reportRow('invoice_cancelled_skipped', $inv, $block, 'Invoice ini sudah berstatus Cancelled di KE — tidak diproses otomatis, perlu cek manual.');

            return;
        }

        $kePaid = (float) $ar->paid_amount;

        if ($inv->paid <= $kePaid + self::AMOUNT_EPSILON) {
            if ($kePaid > $inv->paid + self::AMOUNT_EPSILON) {
                $summary['ke_higher_than_skybiz_conflict']++;
                $reportRows[] = $this->reportRow('ke_higher_than_skybiz_conflict', $inv, $block, sprintf(
                    'paid_amount di KE (Rp %s) lebih tinggi dari ledger Skybiz (Rp %s) — tidak diturunkan otomatis, perlu cek manual.',
                    number_format($kePaid, 0, ',', '.'), number_format($inv->paid, 0, ',', '.'),
                ));
            } else {
                $summary['invoices_matched_up_to_date']++;
            }

            return;
        }

        // A payment dated before the KE invoice it would settle can't be right — the Skybiz ref
        // pointed at a different invoice. Held for manual review instead of written.
        $invoiceDate = (string) $ar->invoice?->invoice_date;
        foreach ($inv->events as $event) {
            if ($event['date'] !== null && $invoiceDate !== '' && substr((string) $event['date'], 0, 10) < substr($invoiceDate, 0, 10)) {
                $summary['payment_before_invoice']++;
                $reportRows[] = $this->reportRow('payment_before_invoice', $inv, $block, 'Tanggal pembayaran lebih awal dari tanggal invoice KE — kemungkinan salah pasangan, perlu cek manual.');

                return;
            }
        }

        $summary['invoices_to_correct']++;
        $summary['total_amount_to_apply'] += ($inv->paid - $kePaid);

        // Split the delta chronologically across this invoice's own applying events — only the
        // portion of each event that falls above KE's existing paid_amount baseline, so an
        // invoice already partially (or fully) paid for in KE via its own manually-entered
        // Official Receipt is never double-counted. Telescopes exactly to (ledger.paid - kePaid).
        $cumulative = 0.0;
        foreach ($inv->events as $event) {
            $before = $cumulative;
            $cumulative += $event['amount'];

            $portion = max($cumulative, $kePaid) - max($before, $kePaid);
            if ($portion <= self::AMOUNT_EPSILON) {
                continue;
            }

            $groupKey = $blockIndex.'|'.$event['ref'];
            $orGroups[$groupKey]['meta'] ??= [
                'ref' => $event['ref'], 'type' => $event['type'], 'date' => $event['date'],
                'particulars' => $event['particulars'], 'block_label' => $block['label'],
            ];
            $orGroups[$groupKey]['lines'][] = [
                'accounts_receivable_id' => $ar->id,
                'customer_id' => $ar->customer_id,
                'amount' => round($portion, 2),
            ];
        }
    }

    private function reportRow(string $category, object $inv, array $block, string $reason): array
    {
        return [
            'category' => $category, 'status' => 'needs_review',
            'reference' => $inv->raw_ref, 'customer_block' => $block['label'],
            'document_ref' => null, 'amount' => $inv->amount, 'date' => $inv->date,
            'reason' => $reason,
        ];
    }

    /** @return array<int, array<string, scalar|null>> write outcomes, one per customer sub-group within this Skybiz reference */
    private function writeGroup(array $group, Collection $cashAccounts, ChartOfAccount $suspenseAccount, ImportBatch $batch): array
    {
        $meta = $group['meta'];
        $isGj = $meta['type'] === 'GJ';
        $outcomes = [];

        $linesByCustomer = [];
        foreach ($group['lines'] as $line) {
            $linesByCustomer[$line['customer_id']][] = $line;
        }

        foreach ($linesByCustomer as $customerId => $lines) {
            $totalAmount = round(array_sum(array_column($lines, 'amount')), 2);

            if ($isGj) {
                $cashAccountId = $suspenseAccount->id;
                $paymentMethod = PaymentMethod::CASH;
                $remarks = "Rekonsiliasi migrasi Skybiz — sumber: GJ (jurnal penyesuaian) ref {$meta['ref']} tgl {$meta['date']}, bukan pembayaran bank riil.";
            } else {
                [$cashAccount, $guessed] = $this->resolveLedgerCashAccount(['particulars' => $meta['particulars'] ?? '', 'account' => null, 'secondary_reference' => null], $cashAccounts);
                $cashAccountId = $cashAccount->id ?? $suspenseAccount->id;
                $paymentMethod = $cashAccount !== null && str_contains(strtoupper($cashAccount->name), 'KAS') ? PaymentMethod::CASH : PaymentMethod::BANK_TRANSFER;
                $remarks = "Rekonsiliasi migrasi Skybiz — ref asli: {$meta['ref']}.".($guessed ? ' Akun Kas/Bank dipilih otomatis (default) — mohon verifikasi.' : '');
            }

            try {
                DB::transaction(function () use ($customerId, $meta, $totalAmount, $cashAccountId, $paymentMethod, $remarks, $lines, $batch) {
                    $entry = $this->receiptEntryService->create([
                        'payment_type' => 'customer',
                        'customer_id' => $customerId,
                        'receipt_date' => $meta['date'],
                        'cash_account_id' => $cashAccountId,
                        'reference_number' => $meta['ref'],
                        'remarks' => $remarks,
                        'total_amount' => $totalAmount,
                        'payment_method' => $paymentMethod->value,
                    ]);
                    $entry->update(['source' => 'import', 'import_batch_id' => $batch->id, 'imported_at' => now()]);
                    $entry = $this->receiptEntryService->submit($entry);

                    $this->paymentAllocationService->allocateBatch($entry, array_map(
                        fn ($l) => ['accounts_receivable_id' => $l['accounts_receivable_id'], 'amount' => $l['amount']],
                        $lines,
                    ));
                });

                $outcomes[] = ['category' => 'success', 'status' => 'success', 'reference' => $meta['ref'], 'customer_block' => $meta['block_label'], 'document_ref' => null, 'amount' => $totalAmount, 'date' => $meta['date'], 'reason' => null];
            } catch (Throwable $e) {
                $outcomes[] = ['category' => 'failed', 'status' => 'failed', 'reference' => $meta['ref'], 'customer_block' => $meta['block_label'], 'document_ref' => null, 'amount' => $totalAmount, 'date' => $meta['date'], 'reason' => $e->getMessage()];
            }
        }

        return $outcomes;
    }
}
