<?php

namespace App\Services;

use App\Enums\AccountsPayableStatus;
use App\Enums\DocumentStatus;
use App\Enums\PaymentEntryType;
use App\Exceptions\BusinessException;
use App\Models\AccountsPayable;
use App\Models\PaymentEntry;
use App\Models\PurchaseInvoice;
use App\Repositories\AccountsPayableRepository;
use App\Support\SettlementStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccountsPayableService
{
    public function __construct(protected AccountsPayableRepository $accountsPayableRepository) {}

    public function list(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->accountsPayableRepository->search($filters, $perPage);
    }

    /** Unpaginated, same filters as list() — for AP Detail's Export. Mirrors AccountsReceivableService::listAll(). */
    public function listAll(array $filters): Collection
    {
        return $this->accountsPayableRepository->searchAll($filters);
    }

    /**
     * AP Detail Report's "Total Hutang" card — sums amount-paid_amount over
     * the same filtered row set list() returns, so the figure always
     * matches what's on screen regardless of pagination. Mirrors
     * AccountsReceivableService::outstandingTotal().
     */
    public function outstandingTotal(array $filters): float
    {
        return $this->accountsPayableRepository->outstandingTotal($filters);
    }

    /** Thin passthrough — see AccountsPayableRepository::paidAmountFor(). */
    public function paidAmountFor(string $accountsPayableId): float
    {
        return $this->accountsPayableRepository->paidAmountFor($accountsPayableId);
    }

    /** "Perincian Hutang" — same filtered row set as list()/listAll(), grouped by Supplier with due-date-anchored aging buckets, aggregated in SQL. Thin passthrough. */
    public function groupedDetail(array $filters): array
    {
        $rows = $this->accountsPayableRepository->groupedBySupplierAgingBuckets($filters);

        return [
            'rows' => $rows,
            'total' => [
                'not_due' => array_sum(array_column($rows, 'not_due')),
                'due_1_30' => array_sum(array_column($rows, 'due_1_30')),
                'due_31_60' => array_sum(array_column($rows, 'due_31_60')),
                'due_61_90' => array_sum(array_column($rows, 'due_61_90')),
                'due_over_90' => array_sum(array_column($rows, 'due_over_90')),
                'total' => array_sum(array_column($rows, 'total')),
            ],
        ];
    }

    /**
     * AP Detail's 4 summary cards, computed via the same outstandingTotal()/
     * unallocatedPaymentVouchers() ground truth as the rest of this report —
     * never a separate calculation. "Jatuh Tempo Minggu Ini" and "Sudah
     * Lewat Jatuh Tempo" are just outstandingTotal() with a due_date range,
     * same filter semantics the Aging List's own date filters already use.
     */
    public function summaryCards(): array
    {
        $today = now()->toDateString();
        $endOfWeek = now()->endOfWeek()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        return [
            'total_outstanding' => $this->outstandingTotal([]),
            'due_this_week' => $this->outstandingTotal(['date_from' => $today, 'date_to' => $endOfWeek]),
            'overdue' => $this->outstandingTotal(['date_to' => $yesterday]),
            'unallocated_total' => $this->unallocatedPaymentVouchers()->sum('unallocated_amount_computed'),
        ];
    }

    /**
     * "Uang Muka / Belum Teralokasi" panel — Supplier Payment Vouchers with
     * money not yet applied to any invoice. "Allocated" here is a live SUM
     * over payment_entry_allocations (via items(), scoped to non-reversed
     * rows), not the payment_entries.allocated_amount cache column — same
     * ground-truth rule as AccountsPayableRepository's paid-amount join. A
     * separate query against payment_entries, never touching
     * accounts_payables — structurally guarantees an unallocated payment
     * can never reduce any invoice's outstanding balance, per the report's
     * own requirement.
     */
    public function unallocatedPaymentVouchers(): Collection
    {
        // withSum's allocated_sum is a per-row correlated subquery column, not a GROUP BY
        // aggregate, so it can't be filtered via whereRaw/havingRaw portably — this panel's row
        // count is inherently small (unallocated vouchers are the exception, not the rule), so
        // filtering the already-fetched set in PHP is the pragmatic choice here, unlike the much
        // larger Aging List/Perincian Hutang queries, which must aggregate/paginate in SQL.
        return PaymentEntry::query()
            ->where('payment_type', PaymentEntryType::SUPPLIER)
            ->where('status', DocumentStatus::SUBMITTED)
            ->withSum(['items as allocated_sum' => fn ($query) => $query->where('is_reversed', false)], 'allocated_amount')
            ->with(['supplier', 'cashAccount'])
            ->latest('payment_date')
            ->get()
            ->map(fn (PaymentEntry $entry) => $entry->setAttribute('unallocated_amount_computed', (float) $entry->total_amount - (float) ($entry->allocated_sum ?? 0)))
            ->filter(fn (PaymentEntry $entry) => $entry->unallocated_amount_computed > 0)
            ->values();
    }

    /**
     * Called only by PurchaseInvoiceService::submit() — Accounts Payable is a
     * system-generated side effect, never created directly by a user.
     * Amount is the Purchase Invoice's grand_total (authoritative, includes
     * tax), not re-derived from Goods Receipt lines. purchase_order_id/
     * goods_receipt_id are populated from the Invoice's anchor columns —
     * legacy fields kept for existing report queries, invoice_id is now the
     * authoritative link.
     */
    public function createFromInvoice(PurchaseInvoice $purchaseInvoice): AccountsPayable
    {
        return DB::transaction(function () use ($purchaseInvoice) {
            return $this->accountsPayableRepository->create([
                'supplier_id' => $purchaseInvoice->supplier_id,
                'invoice_id' => $purchaseInvoice->id,
                'purchase_order_id' => $purchaseInvoice->purchase_order_id,
                'goods_receipt_id' => $purchaseInvoice->goods_receipt_id,
                'reference_number' => $purchaseInvoice->document_number,
                'amount' => $purchaseInvoice->grand_total,
                'paid_amount' => 0,
                'due_date' => $purchaseInvoice->due_date,
                'status' => AccountsPayableStatus::UNPAID,
            ]);
        });
    }

    /**
     * Called only by PaymentEntryService::submit() — applies one
     * settlement line against this payable and recomputes its status.
     */
    public function settle(AccountsPayable $accountsPayable, float $amount): AccountsPayable
    {
        return DB::transaction(function () use ($accountsPayable, $amount) {
            $newPaidAmount = $accountsPayable->paid_amount + $amount;
            $newStatus = AccountsPayableStatus::from(
                SettlementStatus::resolve((float) $accountsPayable->amount, $newPaidAmount)
            );

            $this->accountsPayableRepository->applySettlement($accountsPayable, $newPaidAmount, $newStatus);

            return $accountsPayable->fresh();
        });
    }

    /**
     * Symmetric to settle() — called only by PaymentEntryAllocationService::reverse()
     * to undo one settlement line and recompute status. Amount is clamped at
     * 0 rather than going negative; a reversal can never undo more than was
     * ever settled, so this is a defensive floor, not a real-world branch.
     * Mirrors AccountsReceivableService::unsettle().
     */
    public function unsettle(AccountsPayable $accountsPayable, float $amount): AccountsPayable
    {
        return DB::transaction(function () use ($accountsPayable, $amount) {
            $newPaidAmount = max(0, $accountsPayable->paid_amount - $amount);
            $newStatus = AccountsPayableStatus::from(
                SettlementStatus::resolve((float) $accountsPayable->amount, $newPaidAmount)
            );

            $this->accountsPayableRepository->applySettlement($accountsPayable, $newPaidAmount, $newStatus);

            return $accountsPayable->fresh();
        });
    }

    /**
     * Shared by PaymentEntryService and PaymentEntryAllocationService — the
     * one place "can this much be applied to this payable" is decided, so
     * both callers stay consistent instead of each re-deriving the math.
     * Moved here from PaymentEntryService (was private, inline) now that
     * both services need it. Mirrors AccountsReceivableService::
     * assertWithinOutstanding().
     */
    public function assertWithinOutstanding(AccountsPayable $accountsPayable, float $amount): void
    {
        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        $outstanding = (float) $accountsPayable->amount - (float) $accountsPayable->paid_amount;

        if ($amount > $outstanding) {
            throw new BusinessException("Amount ({$amount}) exceeds outstanding payable ({$outstanding}) for {$accountsPayable->reference_number}.");
        }
    }

    /**
     * Reduces the payable's face amount — called only by
     * PurchaseReturnService::submit(). Distinct from settle()/unsettle(),
     * which only ever move paid_amount: a Purchase Return changes what's
     * actually owed, not how much of it has been paid. Mirrors
     * AccountsReceivableService::writeDown().
     */
    public function writeDown(AccountsPayable $accountsPayable, float $amount): AccountsPayable
    {
        return DB::transaction(function () use ($accountsPayable, $amount) {
            $newAmount = (float) $accountsPayable->amount - $amount;
            $newCreditedAmount = (float) $accountsPayable->credited_amount + $amount;
            $newStatus = AccountsPayableStatus::from(
                SettlementStatus::resolve($newAmount, (float) $accountsPayable->paid_amount)
            );

            $this->accountsPayableRepository->applyWriteDown($accountsPayable, $newAmount, $newCreditedAmount, $newStatus);

            return $accountsPayable->fresh();
        });
    }

    /** Symmetric to writeDown() — called only by PurchaseReturnService::reverse(). */
    public function restoreWriteDown(AccountsPayable $accountsPayable, float $amount): AccountsPayable
    {
        return DB::transaction(function () use ($accountsPayable, $amount) {
            $newAmount = (float) $accountsPayable->amount + $amount;
            $newCreditedAmount = max(0, (float) $accountsPayable->credited_amount - $amount);
            $newStatus = AccountsPayableStatus::from(
                SettlementStatus::resolve($newAmount, (float) $accountsPayable->paid_amount)
            );

            $this->accountsPayableRepository->applyWriteDown($accountsPayable, $newAmount, $newCreditedAmount, $newStatus);

            return $accountsPayable->fresh();
        });
    }

    /**
     * The Purchase Return equivalent of assertWithinOutstanding() — caps
     * against what's still returnable. `amount` is already net of every
     * prior return (writeDown() subtracts from it directly), so the
     * current `amount` field *is* the remaining returnable balance —
     * `credited_amount` is a separate cumulative cache for display only
     * and must not be subtracted again here. Mirrors
     * AccountsReceivableService::assertWithinCreditableBalance().
     */
    public function assertWithinCreditableBalance(AccountsPayable $accountsPayable, float $amount): void
    {
        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        $creditable = (float) $accountsPayable->amount;

        if ($amount > $creditable) {
            throw new BusinessException("Amount ({$amount}) exceeds the remaining returnable balance ({$creditable}) for {$accountsPayable->reference_number}.");
        }
    }
}
