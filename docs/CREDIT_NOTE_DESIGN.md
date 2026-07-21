# Credit Note — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests until approved.

Frozen inputs this design must not modify: **Accounting Engine, Payment Allocation, Receipt Entry, Accounts Receivable** — every integration point below is additive (new columns, new methods, new enum cases), never a change to existing method signatures or existing journal shapes.

## 0. What already exists (grounding)

Confirmed by reading the current code before writing anything below:

- `Invoice::cancel()` is called from `InvoiceService::cancel()`, which **already refuses to run if `accounts_receivable.paid_amount > 0`**, and its docblock already says corrections flow through "Credit Note (future module)". It does not touch the ledger — it hard-deletes the `AccountsReceivable` row and flips status to `CANCELLED`. This is a dead end for anything already paid; Credit Note is not a nice-to-have, it's the *only* path for correcting a paid or partially-paid Invoice.
- `AccountingService::reverseForDocument(Model $referenceDocument): ?JournalEntry` already exists and is already unused — its docblock literally says *"a future Credit Note module is the intended caller"*. `PaymentAllocationService::reverse()` is the working precedent for calling it.
- The morph map (`AppServiceProvider::boot()`) already documents that adding a reference type is a one-line change — `'credit_note' => CreditNote::class`.
- `ApprovalFlow` (table + model) exists but its own docblock says *"Structure only — no approval workflow logic is implemented yet."* Nothing in the codebase uses it. Credit Note is a legitimate first real consumer, or Credit Note can skip it entirely in favor of permission gating like every other module — this is decided in §4.
- **No COGS is ever posted anywhere in this codebase.** `grep` for `cogs`/`cost_of_goods`/account code `5000` across `app/` returns nothing. `Delivery`/`DeliveryItem` have no cost column. `5000 Cost of Goods Sold` exists only as a seeded, unused Chart of Account row. This is a real, pre-existing gap — Credit Note does not close it (see §5).
- **No inventory-reversal voucher type exists.** `StockVoucherType` has exactly 4 cases (`STOCK_IN`, `GOODS_RECEIPT`, `DELIVERY`, `STOCK_ADJUSTMENT`), none a return. `Delivery::cancel()` is hard-blocked with a docblock naming this exact gap. Credit Note's "returned goods" line is the first thing that will ever call `StockLedgerService::record()` with a return semantic.
- `Invoice.tax_amount` is a **flat, user-entered header amount**, not derived from the `Tax` model/rate table, and has no per-line equivalent on `InvoiceItem`. A Credit Note's tax adjustment must work the same way — a flat portion of that header amount, not a per-line recompute.
- Every Invoice traces to exactly one Delivery of physical `Item`s (`InvoiceService::create()` requires a submitted, not-yet-invoiced `delivery_id`). There is no separate "service invoice" data shape in this ERP today. "Service Refund" is therefore not a distinct mechanism — see §1.

---

## 1. Business Flow

Every Credit Note belongs to exactly **one** Invoice (mirrors `Invoice belongsTo Delivery` — one-to-one-per-document, many documents over time). A single Invoice can have **multiple** Credit Notes over its life, as long as their combined value never exceeds what's left to credit.

The **reason** a user picks is a classification for reporting and for defaulting the editor's field, but there is exactly **one mechanism** underneath all six — a Credit Note is always: some lines, each crediting back a qty and/or amount against a specific `InvoiceItem`, plus optional header-level discount/tax adjustment, resulting in one `total_amount` that reduces the Invoice's receivable. No reason gets its own code path in the service layer — only its own *defaults* in the UI. This avoids a reason-keyed switch statement growing inside `CreditNoteService` as more reasons get added later.

| Reason | What actually happens | Restocks inventory? |
|---|---|---|
| **Full Credit** | Every remaining creditable qty/amount on every line is credited in one Credit Note. `total_amount` must equal the Invoice's full remaining creditable balance (`grand_total − credited_amount`) — enforced, not just a UI default. Used for a wrong invoice issued entirely in error, or a full order cancellation after invoicing. | Yes, by default (goods physically come back) — user can uncheck per line if goods are damaged/unsellable. |
| **Partial Credit** | A subset of lines, or partial qty/amount on some lines, credited. Anything not covered stays owed. | Yes, by default, per line. |
| **Price Adjustment** | `qty_credited = 0` on the line; only `amount` is set (a rate correction — e.g. the invoice was raised at the wrong price). No goods move. | No — forced off, since no qty is being returned. |
| **Returned Goods** | Physical return of some or all of a line's quantity. `qty_credited > 0`, `amount` usually `= qty_credited × rate` (can be overridden for a partial-condition allowance, e.g. restocking fee). | Yes, by default; per-line override lets a damaged-goods return skip restocking while still crediting the customer. |
| **Service Refund** | Mechanically identical to Price Adjustment or Partial Credit on a non-goods line — this ERP has no distinct "service item" flag today (see §0). `qty_credited = 0`, `restock` forced off, reason recorded purely for reporting/filtering. **Not a new mechanism** — documented here so the UI can label it distinctly without the backend needing to know the difference. | No. |
| **Tax Adjustment** | Header-level only: `credit_note_items` can be empty (or zero-amount), `tax_amount` is set directly, `subtotal = 0`. Corrects a wrongly-charged tax amount without touching revenue or goods. | No. |

Common validation across every reason (detailed in §4):
- The Invoice must be `submitted` (not `draft`, not `cancelled`).
- A line can never credit more qty or more amount than that `InvoiceItem` has left after every prior, non-reversed Credit Note against it.
- The Credit Note's `total_amount` can never exceed the Invoice's remaining creditable balance.
- A reversed Credit Note's lines free up their qty/amount again for future Credit Notes (§7, "Reverse credit note").

---

## 2. Database Design

Two new tables. Everything else is reused; only one additive column on an existing table.

### `credit_notes` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `document_number`, `status`, `revision`, `submitted_at`, `cancelled_at` | Documentable's standard columns | `documentType() = 'credit_note'`, claims a new `CN-` naming series (§8 references the seeder pattern). |
| `invoice_id` | uuid FK → `invoices`, `restrictOnDelete()` | The one Invoice this note corrects. Required, not nullable — there is no "standalone" Credit Note in this design, same as Invoice always requiring a Delivery. |
| `customer_id` | uuid FK → `customers`, `restrictOnDelete()` | Denormalized from `invoice.customer_id` — same convention as `accounts_receivables.customer_id` / `receipt_entries.customer_id`, avoids a join on every list/filter query. |
| `credit_note_date` | date | |
| `reason` | string, cast to `CreditNoteReason` enum | `full_credit\|partial_credit\|price_adjustment\|returned_goods\|service_refund\|tax_adjustment`. Classification only (§1) — never branched on on its own inside the service. |
| `subtotal` | decimal(15,2) | Sum of `credit_note_items.amount`. |
| `discount_amount` | decimal(15,2) default 0 | Portion of the Invoice's original discount being taken back (mirrors `invoices.discount_amount`). |
| `tax_amount` | decimal(15,2) default 0 | Portion of the Invoice's flat header tax being reversed (mirrors `invoices.tax_amount` — same flat-amount model, see §0). |
| `total_amount` | decimal(15,2) | `subtotal − discount_amount + tax_amount` — same formula shape as `invoices.grand_total`, and the amount actually written down against the receivable. |
| `remarks` | text nullable | |
| `is_reversed` | boolean default false | Set by `CreditNoteService::reverse()`. Not soft-delete — a reversed Credit Note must stay visible in the Invoice's history, same reasoning `payment_allocations.is_reversed` already established. |
| `reversed_at` | datetime nullable | |
| audit + soft-delete columns | `created_by`/`updated_by`/`deleted_by` + `timestamps()` + `softDeletes()` | `HasAuditTrail` convention, same as every other document table. |

**Why `is_reversed` instead of JournalEntry's "create a new reversing document" pattern:** JournalEntry's `reverse()` creates a brand-new Journal Entry and links `reverses_id`/`reversed_by_id`, because reversing a journal entry is a *recurring, expected* business primitive that itself must leave an immutable trail usable by other modules (Invoice, Receipt Entry, Payment Allocation, and now Credit Note all call `reverseForDocument()` routinely). Reversing a *Credit Note* is not that — it is an exceptional correction of an operator mistake, the same category of event as `PaymentAllocationService::reverse()`, which uses a flag on the same row rather than minting a new document. This design follows that closer precedent. The underlying **ledger** correction still goes through `AccountingService::reverseForDocument()` unchanged, which *does* create a new, immutable Journal Entry — only the Credit Note's own row is flagged, not duplicated.

### `credit_note_items` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `credit_note_id` | uuid FK → `credit_notes`, `cascadeOnDelete()` | Same cascade convention as `invoice_items` → `invoices`. |
| `invoice_item_id` | uuid FK → `invoice_items`, `restrictOnDelete()` | The specific original line being credited. Always required — even a Tax Adjustment note with an empty line set has zero rows here, never a row with a null reference. |
| `item_id`, `item_code`, `item_name`, `uom` | FK + string snapshots | Same snapshot-at-transaction-time convention as `InvoiceItem`/`DeliveryItem`/`SalesOrderItem` — a later item rename must not change history. |
| `qty_credited` | integer default 0 | `0` for Price Adjustment / Service Refund / Tax Adjustment lines. |
| `rate` | decimal(15,2) | Snapshot of the rate being credited — normally equals `invoice_item.rate`, can differ for a Price Adjustment (the corrected rate). |
| `amount` | decimal(15,2) | The authoritative monetary credit for this line. **Not required to equal `qty_credited × rate`** — this is what makes Price Adjustment (qty 0, amount ≠ 0) representable without a separate table. |
| `restock` | boolean default false | Whether `qty_credited` should flow back into `StockLedger` as an IN transaction. Per-line, not per-note — a "Returned Goods" note can restock some lines and not others (e.g. damaged units). |
| audit + soft-delete columns | | |

### `accounts_receivables` — one additive column, no other change

Following the exact precedent of `2026_07_25_000003_add_invoice_id_to_accounts_receivables_table.php` and `2026_07_27_000001_add_allocated_amount_to_receipt_entries_table.php` — a small, separate, additive migration:

| Column | Type | Notes |
|---|---|---|
| `credited_amount` | decimal(15,2) default 0 | Cache, derived from `credit_notes` — mirrors `receipt_entries.allocated_amount`'s exact "cache, derived from X" convention. Incremented by `CreditNoteService::submit()`, decremented by `CreditNoteService::reverse()`. Lets `outstanding_amount` computations and the "remaining creditable balance" guard (§4) avoid a `SUM()` query on every validation. |

No FK is added from `accounts_receivables` to `credit_notes` — the relationship is already fully expressed through `credit_notes.invoice_id` → `invoices` ← `accounts_receivables.invoice_id` (one-hop join), so nothing new is needed for traceability.

### Chart of Accounts — one new seed row (recommended, not required)

`4050 Sales Returns and Allowances` (type `revenue`, contra-revenue by convention — posted as a debit to reduce net revenue, same numbering-block placement next to `4000 Sales Revenue`). This is optional: Credit Note's journal (§5) can post straight against `4000` instead. The separate account is recommended purely for reporting (gross revenue vs. returns visibility), at zero engine complexity cost — it's a seed-data decision, not a design dependency. If accepted, add it to `ChartOfAccountsSeeder`; nothing else changes.

### New migrations (filenames, not created yet)

Following this repo's one-table-per-migration convention and continuing the existing date sequence (latest is `2026_07_27_000002`):

```
2026_07_28_000001_create_credit_notes_table.php
2026_07_28_000002_create_credit_note_items_table.php
2026_07_28_000003_add_credited_amount_to_accounts_receivables_table.php
```

---

## 3. Domain Model

```
Invoice (existing, unchanged)
  ├─ hasMany InvoiceItem (existing, unchanged)
  ├─ hasOne AccountsReceivable (existing, unchanged)
  └─ hasMany CreditNote (new)

CreditNote (new — Documentable)
  ├─ belongsTo Invoice
  ├─ belongsTo Customer   (denormalized)
  └─ hasMany CreditNoteItem

CreditNoteItem (new — not Documentable, a line fact, same category as InvoiceItem/PaymentAllocation)
  ├─ belongsTo CreditNote
  ├─ belongsTo InvoiceItem
  └─ belongsTo Item
```

### Responsibilities (avoiding a God Object)

| Entity / Service | Owns | Explicitly does NOT own |
|---|---|---|
| `CreditNote` (model) | Its own Documentable lifecycle; `journalLines()` — the debit/credit breakdown derived from its own stored fields, exactly like `Invoice::journalLines()`. `cancel()` overridden to throw (same precedent as `Delivery`/`ReceiptEntry`/`JournalEntry` — a submitted document is never silently discarded). | Posting to the ledger, writing down the receivable, moving stock — all delegated. |
| `CreditNoteItem` (model) | One line's qty/amount/restock facts. | Any validation against the Invoice — that's the service's job, not the model's. |
| `CreditNoteService` | Validation (qty/amount caps, remaining-balance guard), transaction boundaries, orchestrating the three side effects below in one `DB::transaction`, approval gating. | Journal posting logic, AR math, stock math — all reused, not reimplemented (§4). |
| `AccountsReceivableService` (existing, extended) | Two **new** methods only: `writeDown()` / `restoreWriteDown()` (§4). `settle()`/`unsettle()`/`assertWithinOutstanding()` untouched. | Nothing about Credit Note's own validation. |
| `AccountingService` / `JournalEntryService` (existing, untouched) | `postForDocument()` / `reverseForDocument()` — exactly the same two calls every other module already makes. | Zero Credit-Note-specific code — this is the proof that the Accounting Engine needed no changes to support a 4th caller. |
| `StockLedgerService` (existing, extended) | One new `StockVoucherType` case consumed by its existing `record()` method — no new method. | Nothing else changes. |

### Lifecycle

```
DRAFT --submit()--> SUBMITTED --reverse()--> SUBMITTED (is_reversed = true)
  |
  delete() [draft only]
```

There is no `CANCELLED` state reachable in practice for a Credit Note beyond what `Documentable` already guards (`cancel()` requires `status === SUBMITTED`, but is overridden to unconditionally throw — matching `Delivery`, `ReceiptEntry`, `JournalEntry`). A draft mistake is deleted, not cancelled. A submitted mistake is reversed, not cancelled. This keeps exactly one correction path per lifecycle stage, no ambiguity between "cancel" and "reverse."

---

## 4. Service Layer

```php
class CreditNoteService
{
    public function __construct(
        protected CreditNoteRepository $creditNoteRepository,
        protected InvoiceRepository $invoiceRepository,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountingService $accountingService,
        protected StockLedgerService $stockLedgerService,
    ) {}

    public function list(array $filters, int $perPage = 15): LengthAwarePaginator;

    public function create(array $data): CreditNote;              // draft — validates against Invoice + prior credits, does not post
    public function update(CreditNote $creditNote, array $data): CreditNote; // draft only
    public function delete(CreditNote $creditNote): void;          // draft only

    public function submit(CreditNote $creditNote): CreditNote;    // the posting operation — see below
    public function reverse(CreditNote $creditNote): CreditNote;   // the undo operation — see below

    public function requestApproval(CreditNote $creditNote): ApprovalFlow;
    public function decideApproval(ApprovalFlow $approvalFlow, ApprovalStatus $decision, ?string $remarks = null): ApprovalFlow;
}
```

### Validation (enforced in `create()`/`update()`, re-checked in `submit()`)

All of these are the "no duplicated logic" requirement from the ticket applied concretely — every number below is read from existing columns, nothing is recomputed by guesswork:

1. `invoice.status === SUBMITTED`. A draft or cancelled Invoice cannot be credited.
2. Every `credit_note_items[].invoice_item_id` must belong to `invoice_id` (reject cross-invoice lines).
3. Per line: `qty_credited ≤ invoice_item.qty − Σ(qty_credited across prior non-reversed CreditNoteItems for that invoice_item_id)`.
4. Per line: `amount ≤ invoice_item.amount − Σ(amount across prior non-reversed CreditNoteItems for that invoice_item_id)`.
5. Header: `total_amount ≤ invoice.grand_total − invoice.accountsReceivable.credited_amount` (the master "don't over-credit" guard — this is what makes "Multiple credit notes" safe, §7).
6. If `reason === FULL_CREDIT`: `total_amount` must **equal** (not just be ≤) the Invoice's remaining creditable balance — a Full Credit that doesn't cover everything left is a contradiction, rejected with a clear message telling the user to use Partial Credit instead.
7. `restock === true` only allowed where `qty_credited > 0`.
8. Same duplicate-reference guard already used by `ReceiptEntryService`/`PaymentAllocationService`: an `invoice_item_id` cannot appear twice in one Credit Note's line set.

Guards 3–5 read `invoice_item`/`accounts_receivable.credited_amount`, both of which are mutated concurrently by other Credit Notes — see row-locking below.

### `submit()` — the posting operation

```php
public function submit(CreditNote $creditNote): CreditNote
{
    return DB::transaction(function () use ($creditNote) {
        $this->assertApproved($creditNote); // no-op if under the approval threshold, see below

        $accountsReceivable = $this->accountsReceivableRepository->lockForUpdate(
            $creditNote->invoice->accountsReceivable->id
        );
        $this->assertWithinRemainingBalance($creditNote, $accountsReceivable); // re-validates guard 5 against the freshly-locked row

        $creditNote->submit(); // Documentable: DRAFT -> SUBMITTED

        $this->accountsReceivableService->writeDown($accountsReceivable, (float) $creditNote->total_amount);

        $this->accountingService->postForDocument(
            $creditNote,
            $creditNote->journalLines(),
            "Credit Note {$creditNote->document_number} for Invoice {$creditNote->invoice->document_number}",
            $creditNote->credit_note_date->toDateString(),
        );

        foreach ($creditNote->items->where('restock', true) as $line) {
            $this->stockLedgerService->record(
                itemId: $line->item_id,
                warehouseId: $creditNote->invoice->delivery->warehouse_id, // same warehouse the goods originally left from
                transactionType: StockTransactionType::IN,
                voucherType: StockVoucherType::CREDIT_NOTE, // new case, see §5
                voucherId: $creditNote->id,
                qtyChange: $line->qty_credited,
                postingDatetime: now(),
            );
        }

        return $creditNote->fresh([...]);
    });
}
```

**Row locking**: locks the `AccountsReceivable` row before the final balance check, same convention `NamingSeriesRepository::lockDefaultForType()` and `PaymentAllocationService::allocateBatch()` already established — two concurrent Credit Notes against the same Invoice cannot both pass validation against a stale `credited_amount` and jointly over-credit (§7, "Concurrency").

**Transaction boundary**: the whole thing is one `DB::transaction()`. If `postForDocument()` throws (e.g. an inactive Chart of Account), everything rolls back — the AR's `credited_amount`/`amount` write-down, the status flip, and any partial stock-ledger writes already issued in the loop. This is the exact "atomicity is structural" pattern proven in Sprint 11/12's rollback tests, reused verbatim.

### `reverse()` — the undo operation

```php
public function reverse(CreditNote $creditNote): CreditNote
{
    return DB::transaction(function () use ($creditNote) {
        if ($creditNote->status !== DocumentStatus::SUBMITTED || $creditNote->is_reversed) {
            throw new BusinessException('Only a submitted, not-yet-reversed Credit Note can be reversed.');
        }

        $accountsReceivable = $this->accountsReceivableRepository->lockForUpdate($creditNote->invoice->accountsReceivable->id);

        $this->accountsReceivableService->restoreWriteDown($accountsReceivable, (float) $creditNote->total_amount);
        $this->accountingService->reverseForDocument($creditNote); // reused, unchanged — exact PaymentAllocationService::reverse() pattern

        foreach ($creditNote->items->where('restock', true) as $line) {
            $this->stockLedgerService->record(
                itemId: $line->item_id,
                warehouseId: $creditNote->invoice->delivery->warehouse_id,
                transactionType: StockTransactionType::OUT, // undo the earlier IN
                voucherType: StockVoucherType::CREDIT_NOTE,
                voucherId: $creditNote->id,
                qtyChange: $line->qty_credited,
                postingDatetime: now(),
            );
        }

        $this->creditNoteRepository->update($creditNote, ['is_reversed' => true, 'reversed_at' => now()]);

        return $creditNote->fresh([...]);
    });
}
```

Reversing frees up the credited qty/amount for future Credit Notes automatically — guards 3–5 above sum only *non-reversed* rows, so once `is_reversed = true` those amounts drop out of the running total with no separate "restore the cap" step needed.

### `AccountsReceivableService` — two new methods (extending, not modifying, the existing service)

```php
/** Reduces the receivable's face amount — called only by CreditNoteService::submit(). Symmetric partner to settle()/unsettle(), but on `amount`, not `paid_amount`. */
public function writeDown(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable
{
    return DB::transaction(function () use ($accountsReceivable, $amount) {
        $newAmount = (float) $accountsReceivable->amount - $amount;
        $newStatus = AccountsReceivableStatus::from(
            SettlementStatus::resolve($newAmount, (float) $accountsReceivable->paid_amount)
        );

        $this->accountsReceivableRepository->applyWriteDown($accountsReceivable, $newAmount, $newStatus, $accountsReceivable->credited_amount + $amount);

        return $accountsReceivable->fresh();
    });
}

public function restoreWriteDown(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable { /* symmetric */ }
```

`SettlementStatus::resolve()` is reused completely unchanged — its existing `paidAmount >= amount` check already reports `PAID` even when `paid_amount` now exceeds a shrunken `amount` (the "overpaid" case, §7); no enum change needed there.

### Approval — first real use of the existing `ApprovalFlow` table, kept intentionally simple

Rather than inventing a parallel approval mechanism, or leaving `ApprovalFlow` dormant forever, Credit Note becomes its first consumer — but only for a single, optional step, not a multi-stage chain (YAGNI: nothing in this codebase needs more than that yet, and `ApprovalFlow.step` exists for future extension if it ever does):

- A configurable threshold (e.g. `config('credit-note.approval_threshold')`, a plain config value — not a settings-table UI, which would be over-building for a single number) decides whether a Credit Note needs approval before it can be submitted.
- Below the threshold: `submit()` works directly, gated only by the existing `credit_note.submit` permission (identical to every other module's permission gate).
- At or above the threshold: `CreditNoteService::requestApproval()` creates an `ApprovalFlow` row (`approvable_type = 'credit_note'`, `status = pending`, `step = 1`). `decideApproval()` lets a user with a `credit_note.approve` permission mark it `approved`/`rejected`. `submit()`'s `assertApproved()` step (shown above) throws unless an `approved` `ApprovalFlow` row exists for this Credit Note — a rejected or still-pending one blocks submission.
- This is deliberately the simplest version that satisfies the ticket's explicit "Approval" requirement without redesigning `ApprovalFlow` or inventing a workflow engine.

---

## 5. Accounting

### The journal is `Invoice::journalLines()`, mirrored

The original Invoice posts:

```
Dr 1200 Accounts Receivable   grand_total
Cr 4000 Sales Revenue         subtotal
Cr 2100 Tax Payable           tax_amount   (if > 0)
Dr 4900 Discount Given        discount_amount (if > 0)
```

A Credit Note reverses the *portion* it covers — every debit becomes a credit and vice versa, using the Credit Note's own `subtotal`/`discount_amount`/`tax_amount`/`total_amount` (which represent the credited portion, not the full invoice):

```php
// CreditNote::journalLines() — same shape as Invoice::journalLines(), debit/credit swapped
public function journalLines(): array
{
    $lines = [
        ['account' => '1200', 'type' => 'credit', 'amount' => (float) $this->total_amount],  // Accounts Receivable, reduced
        ['account' => '4000', 'type' => 'debit',  'amount' => (float) $this->subtotal],       // Sales Revenue, reduced
        // or '4050' if the Sales Returns and Allowances account (§2) is adopted
    ];

    if ((float) $this->tax_amount > 0) {
        $lines[] = ['account' => '2100', 'type' => 'debit', 'amount' => (float) $this->tax_amount]; // Tax Payable, reduced
    }

    if ((float) $this->discount_amount > 0) {
        $lines[] = ['account' => '4900', 'type' => 'credit', 'amount' => (float) $this->discount_amount]; // Discount Given, reduced
    }

    return $lines;
}
```

This is balanced by construction for the same reason `Invoice::journalLines()` is: `total_amount = subtotal − discount_amount + tax_amount` (§2), so `Cr side = subtotal + tax_amount = Dr side = total_amount + discount_amount`. `AccountingService::postForDocument()` and `JournalEntryService::create()`'s existing `assertBalanced()` need no changes to accept this.

### Per-reason journal shape

Every reason in §1 produces the *same* `journalLines()` output above — a reason never changes which accounts are touched, only which of `subtotal`/`discount_amount`/`tax_amount`/`total_amount` are non-zero:

- **Full/Partial Credit, Returned Goods**: `subtotal > 0`, everything follows normally.
- **Price Adjustment, Service Refund**: `subtotal > 0` (the price/amount being credited), `tax_amount`/`discount_amount` typically `0` unless the adjustment also changes tax.
- **Tax Adjustment**: `subtotal = 0` (no revenue reversed), only the `2100 Tax Payable` line is non-zero. `journalLines()` naturally produces a 2-line journal (`1200`/`2100` only) since the `4000` line would be `amount: 0` — worth an explicit guard in `journalLines()` to omit zero-amount lines the same way the tax/discount lines are already conditionally included, so `JournalEntryService::assertEachLineIsSingleSided()` never sees a `debit: 0, credit: 0` line.

### Inventory and COGS — explicitly scoped

- **Inventory quantity**: restored via `StockLedgerService::record()` with a new `StockVoucherType::CREDIT_NOTE` case (one enum addition, §0/§4) for lines flagged `restock = true`. This is a pure quantity movement, using the existing engine unchanged.
- **COGS**: **not touched, by design.** Since neither `Delivery` nor `Invoice` ever posts a COGS journal entry (§0), there is nothing for a Credit Note to reverse. Building COGS posting is out of scope for this design — it would require retrofitting `Invoice`/`Delivery`, both of which are frozen this sprint, and is a strictly larger change than Credit Note itself. Flagged as a future enhancement, not a defect in this design.

### Reversal

`CreditNoteService::reverse()` calls `AccountingService::reverseForDocument($creditNote)` unchanged — exactly the mechanism `PaymentAllocationService::reverse()` already proved in Sprint 12. It finds the Credit Note's own posted Journal Entry (via `reference_type = 'credit_note'`) and posts a swapped-debit/credit reversal, leaving the original journal entry completely untouched (`reversed_by_id` set on it). **No journal entry is ever mutated** — the reversal is always a new, immutable entry, satisfying "always use reverse journals" directly.

### Future Payment Allocation interaction

`AccountsReceivable.amount` (mutated by Credit Note) and `AccountsReceivable.paid_amount` (mutated by Payment Allocation) are **orthogonal** — Credit Note never calls into `PaymentAllocationService`, and vice versa. They only ever meet through the shared `AccountsReceivable` row and its existing `outstanding_amount = amount − paid_amount` computation:

- If the AR is unpaid or partially paid, `writeDown()` just shrinks `amount`; `outstanding_amount` shrinks correspondingly; no special case needed.
- If the AR is already fully paid (`paid_amount === amount`) and a Credit Note then shrinks `amount` below `paid_amount`, the AR becomes **overpaid** (`outstanding_amount` goes negative). This is accepted as a valid resulting state — see §7 for the explicit decision — and is exactly why `writeDown()` is not blocked by `paid_amount > 0` the way `InvoiceService::cancel()` blocks itself. Refunding that overpayment (cash back, or applying it to a future invoice) is an explicit **out-of-scope** future "Customer Credit Balance" capability, the same way Sprint 12 scoped out reversing a Payment itself.
- A future Payment Allocation attempt against an overpaid AR is automatically and safely rejected by the existing, unmodified `AccountsReceivableService::assertWithinOutstanding()` guard (a negative `outstanding` makes `amount > outstanding` trivially true for any positive allocation) — **zero changes needed to Payment Allocation** for this design to be safe.

---

## 6. UI

Placed under the Sales module (`features/sales/`), alongside Invoice — same reasoning Invoice itself was placed there rather than under Finance: it's fundamentally a sales-side correction, even though it affects Accounts Receivable. Routes: `/sales/credit-notes`, `/sales/credit-notes/new`, `/sales/credit-notes/:id/edit`, `/sales/credit-notes/:id`.

- **List page** — mirrors `InvoiceListPage`/`IncomingPaymentListPage` exactly: columns Credit Note No, Invoice No (linked), Customer, Reason, Date, Amount, Status. Filters: status, reason, date range, customer.
- **Editor page**:
  - **Invoice selection**: a searchable picker limited to `submitted` invoices with a remaining creditable balance `> 0` (`grand_total − credited_amount`) — same query shape as the outstanding-invoice list `PaymentAllocationDrawer` already fetches, just filtered differently (by creditable balance instead of settlement status).
  - **Line selection**: once an Invoice is picked, its `items` load with each line showing original qty/rate/amount alongside *already-credited* qty/amount (computed from prior Credit Notes) so the user can see the remaining cap before typing. Per line: a qty-credited input (capped client-side at the remaining qty), an amount input (defaults to `qty_credited × rate`, editable for Price Adjustment), and a restock checkbox (defaulted per the reason, per §1's table).
  - **Reason**: a dropdown from `CreditNoteReason`; changing it re-applies the relevant defaults described in §1 to any already-entered lines (e.g. switching to Price Adjustment zeroes every line's `qty_credited` and unchecks restock).
  - **Header discount/tax adjustment**: two optional inputs, same shape as Invoice's own editor fields.
  - **Running total**: subtotal/discount/tax/total footer, same layout as `InvoiceEditorPage`'s summary block, with a live "exceeds remaining balance" validation message instead of a silent clamp — the user should see *why* a number is rejected, not just have it capped invisibly.
- **Approval** (only shown when the amount is at/above the configured threshold, §4): a distinct "Request Approval" action replacing "Submit" on a draft above the threshold; once an `ApprovalFlow` is `approved`, "Submit" becomes available. A pending/rejected state is shown as a banner with the approver's remarks.
- **Posting**: "Submit" button, same placement/style as every other module's submit action.
- **Detail page**: mirrors `InvoiceDetailPage`/`IncomingPaymentDetailPage` — header fields, linked Invoice (clickable, same `resolveSourceDocumentLink` pattern), line table, financial summary, a "Reverse" action (submitted, not yet reversed) with the same confirm-and-toast pattern `PaymentAllocationDrawer`'s reverse button already established, and a "Reversed" badge once flagged.
- **History on the Invoice itself**: `InvoiceDetailPage` gains a new **"Credit Notes"** card, structurally identical to its existing **"Payment History"** card (same `DataTable` pattern, columns: Credit Note No, Date, Reason, Amount, Status) — so a user looking at an Invoice sees every correction against it in one place, the same way they already see every payment.
- **Status display**: reuses `StatusBadge` for draft/submitted, plus a secondary "Reversed" `Badge` — the exact same visual language just shipped for `PaymentAllocation` lines in `IncomingPaymentDetailPage`.

---

## 7. Edge Cases

| Case | Behavior |
|---|---|
| **Already paid invoice** | Allowed. `writeDown()` only touches `amount`, never `paid_amount`. See "Overpaid invoice" below for what happens next. |
| **Partially paid invoice** | Allowed, same as above — `paid_amount` stays exactly as-is; `outstanding_amount` recomputes naturally. |
| **Overpaid invoice** (a Credit Note shrinks `amount` below already-collected `paid_amount`) | Explicitly allowed, not blocked. `outstanding_amount` goes negative (surfaced in the UI as a "credit balance owed to customer"), `SettlementStatus::resolve()` still reports `PAID` (unchanged behavior — it was always `>=`, never `==`). Refunding the overage is out of scope (§5). |
| **Multiple credit notes** (same Invoice, several over time) | Safe by construction — every validation guard (§4, rules 3–5) sums only *non-reversed* prior Credit Notes, and `accounts_receivables.credited_amount` is the running cache checked before each new one posts. |
| **Tax changes** (a Tax Adjustment credit note after a prior Partial Credit already reduced `subtotal`) | Independent — tax and subtotal are tracked as separate running totals against the same Invoice; a Tax Adjustment's guard (rule 5) checks against the Invoice's total remaining balance, which already reflects everything credited so far, tax or not. |
| **Deleted draft** | `delete()` only permitted in `DRAFT` (same `assertDraft`-style guard every service already uses) — a submitted Credit Note can never be deleted, only reversed. Soft-deleted, same as every other document. |
| **Posted note** (attempting to edit/delete/re-submit) | Blocked — `update()`/`delete()` both require `DRAFT`; `submit()` requires `DRAFT` (Documentable's own guard); a second `submit()` call on an already-submitted note throws the standard Documentable "only draft documents can be submitted" error, no Credit-Note-specific code needed. |
| **Reverse credit note** | Only a `SUBMITTED`, not-yet-`is_reversed` note can be reversed (§4). Reversing restores the Invoice's creditable balance, restores stock (for restocked lines), and posts a swapped-debit/credit journal — the original journal entry is never mutated. A second `reverse()` call throws, same idempotency shape as `PaymentAllocationService::reverse()`'s own double-reverse guard. |
| **Concurrency** (two Credit Notes racing against the same Invoice) | `submit()` locks the `AccountsReceivable` row (`lockForUpdate()`) before its final balance re-check (§4) — the second of two concurrent submits to pass validation sees the first one's already-committed `credited_amount` and correctly fails, rather than both succeeding and jointly over-crediting. Same convention as `PaymentAllocationService::allocateBatch()`'s existing lock. |
| **Rollback** | `submit()` and `reverse()` are each one `DB::transaction()`. A failure at any step (an inactive Chart of Account during posting, a stock-ledger failure, a validation re-check failing after the lock) rolls back every side effect from that call — no partial write-down, no orphaned journal entry, no partial stock movement. Same "atomicity is structural" proof pattern as every prior sprint's rollback tests. |

---

## 8. Testing

This codebase has no separate unit-test layer (confirmed in Sprint 11/12) — every test below is a Feature test against a real service stack on the sqlite in-memory test database, same convention as `AccountingEngineTest`/`PaymentAllocationTest`.

- **Feature — happy paths**: Full Credit (covers exact remaining balance), Partial Credit (single line, partial qty), Price Adjustment (qty 0, amount only), Returned Goods (restocks inventory, `StockLedger` row created), Service Refund (no stock movement), Tax Adjustment (only the `2100` line posts, `subtotal = 0`).
- **Feature — journal correctness**: for each reason above, assert the posted Journal Entry is balanced, uses the exact accounts from §5, and `reference_type === 'credit_note'`.
- **Multiple credit notes**: two sequential Credit Notes against the same Invoice, second one capped correctly by the first's `credited_amount`; a third attempt exceeding what's left throws.
- **Overpayment / already-paid interactions**: settle an Invoice's AR fully via Payment Allocation, then submit a Credit Note against it — assert `paid_amount` untouched, `amount` reduced, resulting `outstanding_amount` negative, no exception.
- **Reverse**: submit then reverse a Credit Note — assert `credited_amount` restored, stock restored (for restocked lines), original journal entry untouched with `reversed_by_id` set, reversal journal correctly swapped. Reversing twice throws.
- **Deleted draft**: create a draft, delete it, assert `assertSoftDeleted`; assert a submitted note cannot be deleted (throws).
- **Posted note immutability**: assert `update()` on a submitted note throws; assert a second `submit()` call throws.
- **Rollback**: deactivate a required Chart of Account before `submit()`, assert it throws and nothing committed (`credited_amount` unchanged, no `credit_notes`/`journal_entries`/`stock_ledgers` rows); same for `reverse()`.
- **Concurrency**: two sequential (simulating racing) `submit()` calls against an Invoice with only enough remaining balance for one — assert the second correctly fails after the lock is released, same technique as `PaymentAllocationTest`'s existing concurrency test.
- **Accounting balance**: after a full mixed sequence (Invoice → Partial Credit → Payment Allocation → Tax Adjustment Credit Note → Reverse one of them), assert the sum of all debits equals the sum of all credits across every posted Journal Entry for that Invoice's chain — the same end-to-end "does the ledger still balance" proof `PaymentAllocationTest::test_receiving_then_allocating_a_payment_nets_the_suspense_account_to_zero()` already established for Sprint 12.
- **Approval gating**: a Credit Note at/above the threshold cannot be submitted without an `approved` `ApprovalFlow`; a `rejected` one blocks submission with a clear error; below the threshold, `submit()` works with no `ApprovalFlow` involved at all.

---

## Open Questions

1. **`4050 Sales Returns and Allowances`** (§2/§5) — adopt the separate contra-revenue account, or post straight against `4000 Sales Revenue`? No engine difference either way, purely a chart-of-accounts/reporting decision.
2. **Approval threshold value and permission names** — a concrete default (e.g. `Rp 5,000,000`) and the exact `credit_note.approve` permission needs sign-off; §4's mechanism works at any threshold.
3. **Warehouse for restock** — this design assumes goods return to the same warehouse the original Delivery shipped from (`invoice.delivery.warehouse_id`). If returns should be routable to a different (e.g. "damaged goods") warehouse, that's a small addition to `credit_note_items` (a nullable `warehouse_id` override) — flagged now so it's not a surprise later, not built unless confirmed needed.

Stopping here — no code, no migrations, no tests. Waiting for review.
