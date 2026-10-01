<?php

namespace App\Console\Commands;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use App\Models\CustomerOutstandingSnapshotLine;
use App\Models\Invoice;
use App\Support\SettlementStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time historical correction: every Invoice imported via SalesInvoiceImportService
 * (import_source_type = 'historical_invoice') was created with no AccountsReceivable row at all —
 * InvoiceService::submit() used to skip AR entirely for these (fixed 2026-10-02, see
 * SalesInvoiceImportService's own docblock). Without an AR row, PaymentAllocationService can never
 * find these invoices, so a real Official Receipt against one of these customers sits permanently
 * unallocated, and the separate, frozen, never-synced Customer Outstanding Bills snapshot report
 * keeps showing the invoice as unpaid forever regardless of any later payment.
 *
 * paid_amount is seeded from the Customer Outstanding Bills snapshot (CustomerOutstandingSnapshotLine)
 * where a line's ref_no matches the invoice's own source_document_number — both are the same legacy
 * document number, compared via the *_normalized columns Documentable already keeps in sync (trim +
 * uppercase). An invoice not covered by any snapshot (e.g. dated after the snapshot's as-of date)
 * starts at 0, correct since no payment has ever been recorded against it. When the same ref_no
 * appears in more than one snapshot, the most recently taken one (highest snapshot_as_of_date) wins.
 *
 * Idempotent: an invoice that already has an AccountsReceivable row (a prior run of this command, or
 * a normal Invoice submitted after the InvoiceService fix shipped) is left untouched.
 */
class BackfillHistoricalInvoiceAccountsReceivableCommand extends Command
{
    protected $signature = 'ar:backfill-historical-invoices {--dry-run : Compute and report without saving any changes}';

    protected $description = 'Create the missing AccountsReceivable row for every historical-imported Sales Invoice, seeding paid_amount from the Customer Outstanding Bills snapshot where available.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $paidByRef = $this->loadSnapshotPaidAmounts();

        $alreadyHasAr = Invoice::query()
            ->where('import_source_type', 'historical_invoice')
            ->whereHas('accountsReceivable')
            ->count();

        DB::beginTransaction();

        $created = 0;
        $seededFromSnapshot = 0;

        Invoice::query()
            ->where('import_source_type', 'historical_invoice')
            ->whereDoesntHave('accountsReceivable')
            ->chunkById(500, function ($invoices) use (&$created, &$seededFromSnapshot, $paidByRef) {
                foreach ($invoices as $invoice) {
                    $amount = round((float) $invoice->grand_total, 2);
                    $paidAmount = min($paidByRef[$invoice->source_document_number_normalized] ?? 0.0, $amount);

                    if ($paidAmount > 0) {
                        $seededFromSnapshot++;
                    }

                    AccountsReceivable::query()->create([
                        'customer_id' => $invoice->customer_id,
                        'invoice_id' => $invoice->id,
                        'sales_order_id' => $invoice->sales_order_id,
                        'delivery_id' => $invoice->delivery_id,
                        'branch_id' => $invoice->branch_id,
                        'reference_number' => $invoice->document_number,
                        'amount' => $amount,
                        'paid_amount' => $paidAmount,
                        'due_date' => $invoice->due_date,
                        'status' => AccountsReceivableStatus::from(SettlementStatus::resolve($amount, $paidAmount)),
                    ]);

                    $created++;
                }
            });

        if ($dryRun) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->line('');
        $this->info("AR rows created: {$created}");
        $this->info("  of which seeded with a non-zero paid_amount from the Customer Outstanding Bills snapshot: {$seededFromSnapshot}");
        $this->info("Invoices skipped (already had an AR row): {$alreadyHasAr}");

        return self::SUCCESS;
    }

    /** @return array<string, float> normalized ref_no -> paid_amount, from each ref_no's most recent snapshot */
    private function loadSnapshotPaidAmounts(): array
    {
        $paidByRef = [];

        CustomerOutstandingSnapshotLine::query()
            ->join('customer_outstanding_snapshots', 'customer_outstanding_snapshots.id', '=', 'customer_outstanding_snapshot_lines.snapshot_id')
            ->orderBy('customer_outstanding_snapshots.snapshot_as_of_date')
            ->select('customer_outstanding_snapshot_lines.ref_no', 'customer_outstanding_snapshot_lines.paid_amount')
            ->chunk(1000, function ($lines) use (&$paidByRef) {
                foreach ($lines as $line) {
                    $key = strtoupper(trim((string) $line->ref_no));

                    if ($key !== '') {
                        // Later iteration (more recent snapshot, per orderBy above) overwrites earlier.
                        $paidByRef[$key] = (float) $line->paid_amount;
                    }
                }
            });

        return $paidByRef;
    }
}
