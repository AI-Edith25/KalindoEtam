# Debit Note — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests until approved.

Frozen inputs this design must not modify: **Accounting Engine, Payment Allocation, Credit Note, Receipt Entry, Accounts Receivable** — every integration point below is additive (new columns, new methods, new enum cases), never a change to existing method signatures or existing journal shapes.

## 0. What already exists (grounding)

Confirmed by reading the current code before writing anything below:

- `CreditNoteService` (Sprint 13B, shipped) is the closest precedent and the explicit architectural target ("mirror the architectural quality of Credit Note"). Reading `app/Services/CreditNoteService.php` directly (not just `docs/CREDIT_NOTE_DESIGN.md`, which was written *before* implementation and is aspirational in places) shows what actually shipped: **no approval gating** (no `assertApproved()`, no `requestApproval()`/`decideApproval()` anywhere in the class) and **no stock movement** (`credit_note_items.restock` is stored as intent only — the migration comment literally says *"Intent only this sprint — no StockLedger movement is posted for it yet"*). Debit Note's design below is grounded in the real shipped shape, not the earlier aspirational doc. Where this design proposes Approval (§4, required by this ticket), it is understood to be the **first real implementation** of that gate anywhere in the codebase, not a copy of something Credit Note already does.
- `AccountsReceivableService::writeDown()`/`restoreWriteDown()` exist and are the exact precedent for the two new methods this design needs (`writeUp()`/`restoreWriteUp()`) — same file, same class, additive methods only, nothing existing touched.
- `accounts_receivables.credited_amount` already exists (added by Credit Note, Sprint 13B) as a cache column alongside `amount`/`paid_amount`. This design adds one symmetric sibling column, `debited_amount`.
- `AccountingService::postForDocument()` / `reverseForDocument()` are fully generic (`Model $referenceDocument`, an array of `{account, type, amount}` lines) — proven by Invoice, Receipt Entry, Payment Allocation, and Credit Note all calling them unchanged. A fourth-turned-fifth caller needs zero engine changes.
- The morph map (`AppServiceProvider::boot()`) already has `'credit_note' => CreditNote::class` as a one-line entry — `'debit_note' => DebitNote::class` is the same one-line addition.
- `ApprovalFlow` (table + model) exists, and — confirmed above — has **no real consumer yet anywhere**, not even Credit Note. Debit Note can be its first real consumer, using the same simple single-step threshold gate the Credit Note design doc originally sketched (§4).
- Chart of Accounts already seeds `4100 Other Income` and `2100 Tax Payable` (`ChartOfAccountsSeeder`) — both directly reusable, no new seed row *required* (unlike Credit Note, which needed a new `4050` row). A dedicated account for corrected/additional sales revenue is optional, flagged in Open Questions.
- Every Invoice's items are physical `Item`s copied from its Delivery (`InvoiceService::create()`) — `invoice_items.item_id` is never null. A Debit Note's "Additional Service Charge" and "Freight Adjustment" scenarios are **not** corrections to a physical line — they are new charges with no corresponding `Item` at all. This is the one structural feature Credit Note's line model does not need to support (every `CreditNoteItem` always references a real `InvoiceItem`), and it is the main shape difference in this design (§2).
- Unlike Credit Note, which is bounded above (a line can never credit more than remains, `total_amount` can never exceed the Invoice's remaining creditable balance), a Debit Note has **no natural upper bound** — it is always adding to what's owed. This is the other main asymmetry and it simplifies validation (no "remaining balance" arithmetic to replicate) while shifting more of the real-world risk onto Approval (§4) and reporting, not a structural cap.

---

## 1. Business Flow

Every Debit Note belongs to exactly **one** Invoice (mirrors Credit Note's `belongsTo Invoice` exactly). A single Invoice can have **multiple** Debit Notes over its life — there is no ceiling to enforce between them (§0's second asymmetry), so "multiple Debit Notes" is safe by construction, not by a guard (§7).

As with Credit Note, the **reason** is a classification for reporting and UI defaults — there is exactly **one mechanism** underneath: some lines, each adding an amount (optionally tied back to a specific `InvoiceItem`, optionally a brand-new freestanding charge), plus an optional header tax increase, resulting in one `total_amount` that increases the Invoice's receivable. No reason gets its own code path in the service layer.

| Reason | What actually happens | Tied to an existing InvoiceItem? |
|---|---|---|
| **Under-Billed Invoice** | The original Invoice line was raised at too low a quantity (e.g. Delivery shipped more than was invoiced, or a manual entry error). `qty_adjusted > 0`, `amount` is the make-up charge for the missed quantity. | Yes — `invoice_item_id` required. |
| **Price Correction** | The original Invoice line was raised at the wrong (too low) rate. `qty_adjusted = 0`, only `amount` is set (the rate delta). | Yes — `invoice_item_id` required. |
| **Additional Service Charge** | A new charge for work/services not on the original Invoice at all (installation, expedited handling, etc.) — there is no physical `Item`/`InvoiceItem` to point at. `description` + `amount` only. | No — freestanding line, `invoice_item_id` and `item_id` both null. |
| **Freight Adjustment** | Freight/shipping cost that was omitted or under-charged on the original Invoice. Mechanically identical to Additional Service Charge — a freestanding line, reason recorded purely for reporting/filtering, same non-mechanism relationship Credit Note's "Service Refund" already established with "Price Adjustment" (§0 of the Credit Note doc). | No — freestanding line. |
| **Tax Adjustment** | Header-level only: `debit_note_items` can be empty, `tax_amount` is set directly, `subtotal_goods = subtotal_other = 0`. Corrects a wrongly-under-charged tax amount without touching revenue. | No — no lines at all. |

Common validation across every reason (detailed in §4):
- The Invoice must be `submitted` (not `draft`, not `cancelled`) — identical guard to Credit Note.
- A line references at most one of {an existing `InvoiceItem`, a freestanding description} — never both, never neither.
- `total_amount` must be greater than zero. **There is no upper bound** — this is the core mechanical difference from Credit Note (§0).
- A reversed Debit Note's amount stops counting toward the Invoice's receivable again (§7, "Reverse debit note").

---

## 2. Database Design

Two new tables, following the exact one-table-per-document / one-table-per-line convention Credit Note established. One additive column on `accounts_receivables`.

### `debit_notes` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `document_number`, `status`, `revision`, `submitted_at`, `cancelled_at` | Documentable's standard columns | `documentType() = 'debit_note'`, claims a new `DN-` naming series, same seeder pattern as `CN-`. |
| `invoice_id` | uuid FK → `invoices`, `restrictOnDelete()` | Required, not nullable — same reasoning as `credit_notes.invoice_id`. |
| `customer_id` | uuid FK → `customers`, `restrictOnDelete()` | Denormalized from `invoice.customer_id`, same convention as `credit_notes.customer_id`. |
| `debit_note_date` | date | |
| `reason` | string, cast to `DebitNoteReason` enum | `under_billed_invoice\|price_correction\|additional_service_charge\|freight_adjustment\|tax_adjustment`. Classification only — never branched on inside the service (§1). |
| `subtotal_goods` | decimal(15,2) default 0 | Cache, sum of item-linked (`invoice_item_id` not null) `debit_note_items.amount` — posts to `4000 Sales Revenue`. Split from `subtotal_other` so `journalLines()` (§5) never needs a reason-keyed branch to pick an account; the split follows the line's own shape, the same "structure decides, not a switch statement" principle Credit Note's design already used for `reason`. |
| `subtotal_other` | decimal(15,2) default 0 | Cache, sum of freestanding (`invoice_item_id` null) `debit_note_items.amount` — posts to `4100 Other Income`. |
| `tax_amount` | decimal(15,2) default 0 | Additional tax being billed — same flat-header-amount model as `invoices.tax_amount` / `credit_notes.tax_amount`. |
| `total_amount` | decimal(15,2) | `subtotal_goods + subtotal_other + tax_amount`. No `discount_amount` column — see "Why no discount_amount" below. |
| `remarks` | text nullable | |
| `is_reversed` | boolean default false | Same flag-on-the-row pattern as `credit_notes.is_reversed`, same reasoning: reversing a Debit Note is an exceptional correction of an operator mistake, not a recurring primitive — the underlying ledger correction still goes through `AccountingService::reverseForDocument()`, which does create a new, immutable Journal Entry. |
| `reversed_at` | datetime nullable | |
| audit + soft-delete columns | `created_by`/`updated_by`/`deleted_by` + `timestamps()` + `softDeletes()` | `HasAuditTrail` convention. |

**Why no `discount_amount` column:** Invoice and Credit Note both have one because a discount always *reduces* the total, which fits their formula (`subtotal − discount + tax`). A Debit Note only ever increases the receivable, so a "discount" field with subtractive semantics would contradict the document's own purpose. A scenario like "we over-discounted the original invoice and need to claw part of it back" is not a discount at all from the Debit Note's perspective — it's just another additional charge, representable as an ordinary freestanding `debit_note_items` line (reason `Additional Service Charge` or a UI label like "Discount Correction"), no new column needed.

**Why two subtotal columns instead of one:** the alternative — a single `subtotal` plus a reason-based `if ($reason === ...) { $account = ... }` inside `journalLines()` — is exactly the kind of reason-keyed switch statement the Credit Note design deliberately avoided. Splitting by line shape (item-linked vs. freestanding) instead of by reason keeps `journalLines()` a pure function of already-stored fields, with zero knowledge of the `DebitNoteReason` enum at all.

### `debit_note_items` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `debit_note_id` | uuid FK → `debit_notes`, `cascadeOnDelete()` | Same cascade convention as `credit_note_items` → `credit_notes`. |
| `invoice_item_id` | uuid FK → `invoice_items`, **nullable**, `restrictOnDelete()` | Set for Under-Billed Invoice / Price Correction lines (adjusting an existing line). Null for Additional Service Charge / Freight Adjustment lines (§0's structural asymmetry from `CreditNoteItem`, where this column is never null). |
| `item_id`, `item_code`, `item_name`, `uom` | FK + string snapshots, all **nullable** | Populated (snapshot-at-transaction-time, same convention as `CreditNoteItem`) when `invoice_item_id` is set; left null for a freestanding charge, which has no physical `Item`. |
| `description` | string, **required** | Free-text label for the line. Auto-filled from the linked `InvoiceItem.item_name` when `invoice_item_id` is set (editable); required input when it isn't (e.g. "Expedited handling fee", "Freight — express courier"). |
| `qty_adjusted` | integer default 0 | Additional quantity being billed. Non-zero only for Under-Billed Invoice lines; `0` for Price Correction and every freestanding line. |
| `rate` | decimal(15,2) nullable | Snapshot of the corrected/applicable rate when `invoice_item_id` is set; null for a freestanding line where only a lump `amount` applies (there's no per-unit rate for "expedited handling"). |
| `amount` | decimal(15,2) | The authoritative additional charge for this line. Same "not required to equal `qty_adjusted × rate`" flexibility `CreditNoteItem.amount` already has. |
| audit + soft-delete columns | | |

No `restock` column and no `StockLedgerService` interaction anywhere in this design — a Debit Note never moves goods (§0), only money. This is a genuine simplification versus Credit Note, not an oversight.

### `accounts_receivables` — one additive column, no other change

Following the exact precedent of `..._add_credited_amount_to_accounts_receivables_table.php`:

| Column | Type | Notes |
|---|---|---|
| `debited_amount` | decimal(15,2) default 0 | Cache, derived from `debit_notes`, sibling to `credited_amount`. Incremented by `DebitNoteService::submit()` (via `AccountsReceivableService::writeUp()`), decremented by `reverse()` (via `restoreWriteUp()`). |

No FK from `accounts_receivables` to `debit_notes` — same reasoning as Credit Note: the relationship is already expressed through `debit_notes.invoice_id` → `invoices` ← `accounts_receivables.invoice_id`.

### Chart of Accounts — no required seed changes

`4000 Sales Revenue`, `4100 Other Income`, and `2100 Tax Payable` already exist and are directly reusable (§0). A dedicated account (e.g. `4060 Sales Debit Adjustments`, mirroring Credit Note's `4050 Sales Returns and Allowances`) is optional, purely a reporting-granularity decision — see Open Questions.

### New migrations (filenames, not created yet)

Continuing the date sequence after Credit Note's `2026_07_28_000003`:

```
2026_07_29_000001_create_debit_notes_table.php
2026_07_29_000002_create_debit_note_items_table.php
2026_07_29_000003_add_debited_amount_to_accounts_receivables_table.php
```

---

## 3. Domain Model

```
Invoice (existing, unchanged)
  ├─ hasMany InvoiceItem (existing, unchanged)
  ├─ hasOne AccountsReceivable (existing, unchanged)
  ├─ hasMany CreditNote (existing, unchanged)
  └─ hasMany DebitNote (new)

DebitNote (new — Documentable)
  ├─ belongsTo Invoice
  ├─ belongsTo Customer   (denormalized)
  └─ hasMany DebitNoteItem

DebitNoteItem (new — not Documentable, a line fact, same category as CreditNoteItem)
  ├─ belongsTo DebitNote
  ├─ belongsTo InvoiceItem (nullable)
  └─ belongsTo Item (nullable)
```

### Responsibilities (avoiding a God Object)

| Entity / Service | Owns | Explicitly does NOT own |
|---|---|---|
| `DebitNote` (model) | Its own Documentable lifecycle; `journalLines()` — derived purely from its own stored fields, exactly like `CreditNote::journalLines()`. `cancel()` overridden to throw (same precedent as `CreditNote`/`Delivery`/`ReceiptEntry`/`JournalEntry`). | Posting to the ledger, writing up the receivable — both delegated. |
| `DebitNoteItem` (model) | One line's description/qty/amount facts, and whether it's item-linked or freestanding. | Any validation against the Invoice — the service's job. |
| `DebitNoteService` | Validation (line-shape consistency, positive-amount guards — no ceiling arithmetic, §0), transaction boundaries, orchestrating write-up + posting in one `DB::transaction`, approval gating. | Journal posting logic, AR math — reused, not reimplemented (§4). |
| `AccountsReceivableService` (existing, extended) | Two **new** methods only: `writeUp()` / `restoreWriteUp()` (§4). Every existing method (`settle`/`unsettle`/`writeDown`/`restoreWriteDown`/`assertWithinOutstanding`/`assertWithinCreditableBalance`) untouched. | Nothing about Debit Note's own validation. |
| `AccountingService` / `JournalEntryService` (existing, untouched) | `postForDocument()` / `reverseForDocument()` — the same two calls every other module already makes. | Zero Debit-Note-specific code. |

### Lifecycle

```
DRAFT --submit()--> SUBMITTED --reverse()--> SUBMITTED (is_reversed = true)
  |
  delete() [draft only]
```

Identical shape to Credit Note's lifecycle (§3 of the Credit Note doc) — no `CANCELLED` state reachable in practice, `cancel()` unconditionally throws, a draft mistake is deleted, a submitted mistake is reversed.

---

## 4. Service Layer

```php
class DebitNoteService
{
    public function __construct(
        protected DebitNoteRepository $debitNoteRepository,
        protected DebitNoteItemRepository $debitNoteItemRepository,
        protected InvoiceRepository $invoiceRepository,
        protected InvoiceItemRepository $invoiceItemRepository,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountingService $accountingService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function create(array $data): DebitNote;               // draft — validates line shape, does not post
    public function update(DebitNote $debitNote, array $data): DebitNote; // draft only
    public function delete(DebitNote $debitNote): void;            // draft only

    public function submit(DebitNote $debitNote): DebitNote;       // the posting operation — see below
    public function reverse(DebitNote $debitNote): DebitNote;      // the undo operation — see below

    public function requestApproval(DebitNote $debitNote): ApprovalFlow;
    public function decideApproval(ApprovalFlow $approvalFlow, ApprovalStatus $decision, ?string $remarks = null): ApprovalFlow;
}
```

### Validation (enforced in `create()`/`update()`, re-checked in `submit()`)

1. `invoice.status === SUBMITTED`. A draft or cancelled Invoice cannot be debited.
2. Every line with `invoice_item_id` set must belong to `invoice_id` (reject cross-invoice lines) — same guard as Credit Note's rule 2.
3. Per line: exactly one of {`invoice_item_id` set + snapshot fields populated} or {`invoice_item_id` null + `description` provided}. A line with both or neither is rejected — this is the one validation rule Credit Note's line model never needed.
4. Per line: `amount > 0`. `qty_adjusted < 0` rejected. No upper bound — the core asymmetry from Credit Note's rules 3/4 (§0).
5. Same duplicate-reference guard as Credit Note, scoped to item-linked lines only: an `invoice_item_id` cannot appear twice in one Debit Note (freestanding lines have nothing to deduplicate against, since they carry no reference).
6. Header: `total_amount > 0`. No `total_amount ≤ remaining balance` check exists, because there is no remaining balance to exceed — replacing Credit Note's rule 5.
7. If `reason === TAX_ADJUSTMENT`: `items` must be empty and `tax_amount > 0` — mirrors Credit Note's Tax Adjustment shape (empty lines, header-only), enforced instead of just defaulted, same as Credit Note's Full Credit equality check is enforced rather than just defaulted.

There is deliberately no rule reading `accounts_receivable.amount` during validation (unlike Credit Note's rules 3–5, which all read it) — a Debit Note never needs to know the current balance to decide whether it's allowed, only Approval (§4 below) cares about the amount, and only for gating, not for a hard cap.

### `submit()` — the posting operation

```php
public function submit(DebitNote $debitNote): DebitNote
{
    return DB::transaction(function () use ($debitNote) {
        $this->assertDraft($debitNote, 'submitted');
        $this->assertApproved($debitNote); // no-op if under the approval threshold, see below

        $accountsReceivable = $this->accountsReceivableRepository
            ->lockManyForUpdate([$debitNote->invoice->accountsReceivable->id])
            ->firstOrFail();

        $debitNote->submit(); // Documentable: DRAFT -> SUBMITTED

        $this->accountsReceivableService->writeUp($accountsReceivable, (float) $debitNote->total_amount);

        $this->accountingService->postForDocument(
            $debitNote,
            $debitNote->journalLines(),
            "Debit Note {$debitNote->document_number} for Invoice {$debitNote->invoice->document_number}",
            $debitNote->debit_note_date->toDateString(),
        );

        return $debitNote->fresh([...]);
    });
}
```

**Row locking**: locks the `AccountsReceivable` row before the write-up, same `lockManyForUpdate()` call `CreditNoteService::submit()` already makes. The reason differs from Credit Note's: there is no ceiling to jointly violate here, but there **is** a classic lost-update race — two concurrent Debit Notes both reading `amount` before either commits would each compute a `newAmount` from the same stale value, and the second `UPDATE` would silently overwrite (lose) the first's increase. The lock closes exactly that gap (§7, "Concurrency").

**Transaction boundary**: one `DB::transaction()`, identical atomicity guarantee to Credit Note — any failure (e.g. an inactive Chart of Account during posting) rolls back the status flip and the AR write-up together.

### `reverse()` — the undo operation

```php
public function reverse(DebitNote $debitNote): DebitNote
{
    return DB::transaction(function () use ($debitNote) {
        if ($debitNote->status !== DocumentStatus::SUBMITTED || $debitNote->is_reversed) {
            throw new BusinessException('Only a submitted, not-yet-reversed Debit Note can be reversed.');
        }

        $accountsReceivable = $this->accountsReceivableRepository
            ->lockManyForUpdate([$debitNote->invoice->accountsReceivable->id])
            ->firstOrFail();

        $this->accountsReceivableService->restoreWriteUp($accountsReceivable, (float) $debitNote->total_amount);
        $this->accountingService->reverseForDocument($debitNote); // reused, unchanged

        $this->debitNoteRepository->update($debitNote, ['is_reversed' => true, 'reversed_at' => now()]);

        return $debitNote->fresh([...]);
    });
}
```

Identical shape to `CreditNoteService::reverse()`, symmetric direction.

### `AccountsReceivableService` — two new methods (extending, not modifying)

```php
/** Increases the receivable's face amount — called only by DebitNoteService::submit(). Symmetric partner to writeDown(), on the same `amount` field. */
public function writeUp(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable
{
    return DB::transaction(function () use ($accountsReceivable, $amount) {
        $newAmount = (float) $accountsReceivable->amount + $amount;
        $newStatus = AccountsReceivableStatus::from(
            SettlementStatus::resolve($newAmount, (float) $accountsReceivable->paid_amount)
        );

        $this->accountsReceivableRepository->applyWriteUp($accountsReceivable, $newAmount, $accountsReceivable->debited_amount + $amount, $newStatus);

        return $accountsReceivable->fresh();
    });
}

public function restoreWriteUp(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable { /* symmetric, amount -= $amount, floored at 0 same as restoreWriteDown() */ }
```

`SettlementStatus::resolve()` is reused completely unchanged — increasing `amount` on an already-`PAID` receivable naturally flips it back to `PARTIAL`/`UNPAID` since the same `paidAmount >= amount` check now fails, exactly the mirror image of the "overpaid" case Credit Note's `writeDown()` already produces.

### Approval — the first real use of `ApprovalFlow` anywhere in the codebase

Credit Note's shipped code has **no** approval gate (§0) — this is new ground, not a copy. Kept to the same minimal shape the Credit Note design doc originally sketched, since nothing in this codebase needs more than a single optional step yet:

- A configurable threshold (`config('debit-note.approval_threshold')`, a plain config value) decides whether a Debit Note needs approval before it can be submitted.
- Below the threshold: `submit()` works directly, gated only by a `debit_note.submit` permission.
- At or above the threshold: `requestApproval()` creates an `ApprovalFlow` row (`approvable_type = 'debit_note'`, `status = pending`, `step = 1`). `decideApproval()` lets a `debit_note.approve`-permitted user mark it `approved`/`rejected`. `submit()`'s `assertApproved()` throws unless an `approved` row exists.
- Approval matters more here than it would for Credit Note: a Debit Note has no structural ceiling (§0), so it is the one place in this design where a mistaken or abusive large charge is stopped by a human, not by arithmetic. This is the concrete reason Approval is a required part of this design rather than an optional nice-to-have.

---

## 5. Accounting

### The journal is `Invoice::journalLines()`, mirrored in the same direction

Unlike Credit Note (which reverses a portion of the original journal — every debit/credit swapped), a Debit Note posts in the **same direction** as the original Invoice, just for a smaller, additional amount:

```
Invoice:      Dr 1200 AR grand_total     |  Cr 4000 Revenue subtotal  |  Cr 2100 Tax tax_amount
Debit Note:   Dr 1200 AR total_amount    |  Cr 4000/4100 (split)      |  Cr 2100 Tax tax_amount
```

```php
// DebitNote::journalLines() — same direction as Invoice::journalLines(), no swap
public function journalLines(): array
{
    $lines = [
        ['account' => '1200', 'type' => 'debit', 'amount' => (float) $this->total_amount], // Accounts Receivable, increased
    ];

    if ((float) $this->subtotal_goods > 0) {
        $lines[] = ['account' => '4000', 'type' => 'credit', 'amount' => (float) $this->subtotal_goods]; // Sales Revenue (item-linked lines)
        // or '4060' if a dedicated account is adopted, see Open Questions
    }

    if ((float) $this->subtotal_other > 0) {
        $lines[] = ['account' => '4100', 'type' => 'credit', 'amount' => (float) $this->subtotal_other]; // Other Income (freestanding lines)
    }

    if ((float) $this->tax_amount > 0) {
        $lines[] = ['account' => '2100', 'type' => 'credit', 'amount' => (float) $this->tax_amount]; // Tax Payable, increased
    }

    return $lines;
}
```

Balanced by construction: `total_amount = subtotal_goods + subtotal_other + tax_amount` (§2), so `Dr side = total_amount = Cr side = subtotal_goods + subtotal_other + tax_amount`. `JournalEntryService::assertBalanced()` needs no changes.

### Per-reason journal shape

Every reason produces the same `journalLines()` output — only which of `subtotal_goods`/`subtotal_other`/`tax_amount` are non-zero changes, driven by line shape (§2), never by reason:

- **Under-Billed Invoice, Price Correction**: `subtotal_goods > 0` (item-linked lines), `subtotal_other = 0`.
- **Additional Service Charge, Freight Adjustment**: `subtotal_other > 0` (freestanding lines), `subtotal_goods = 0`.
- **Tax Adjustment**: both subtotals `0`, only `2100` is non-zero — `journalLines()` naturally omits the zero-amount lines, same conditional-inclusion pattern already proven by Credit Note's Tax Adjustment case.

### Inventory — explicitly out of scope, by design

A Debit Note never moves goods (§0/§2) — no `StockVoucherType` case, no `StockLedgerService` call anywhere in this design. This is not a gap to flag, unlike Credit Note's COGS gap; a Debit Note's very purpose (correcting what's *owed*) has no physical-goods dimension.

### Reversal

`DebitNoteService::reverse()` calls `AccountingService::reverseForDocument($debitNote)` unchanged — identical mechanism to Credit Note and Payment Allocation. Finds the Debit Note's own posted Journal Entry (`reference_type = 'debit_note'`) and posts a swapped-debit/credit reversal; the original entry is never mutated, only `reversed_by_id` set on it.

### Interaction with Credit Note and Payment Allocation

`AccountsReceivable.amount` is now mutated by **three** independent callers — `InvoiceService` (sets it), `CreditNoteService::writeDown()` (shrinks it), and `DebitNoteService::writeUp()` (grows it) — while `paid_amount` remains exclusively Payment Allocation's. They meet only through the shared row and its existing `outstanding_amount = amount − paid_amount` computation, exactly the "orthogonal, meet only at the shared row" principle Credit Note's design doc §5 already established for Payment Allocation:

- A Debit Note issued after a Credit Note simply raises the ceiling future Credit Notes validate against (`CreditNoteService`'s `assertWithinCreditableBalance()` reads the live `accounts_receivable.amount`, which the Debit Note has already grown) — no special-casing needed in either service.
- A Debit Note issued against an already fully-`PAID` receivable flips its status back to `PARTIAL`/`UNPAID` via the same unmodified `SettlementStatus::resolve()` call Credit Note's "overpaid" case already exercises, just in the opposite direction — the invoice becomes newly outstanding again, which is the entire point of a Debit Note (§ Business Goal).
- A future Payment Allocation against a Debit-Note-grown receivable works with zero changes: `assertWithinOutstanding()` already reads the live `amount − paid_amount`.

---

## 6. UI

Placed under `features/sales/`, alongside Invoice and Credit Note (`frontend/src/features/sales/pages/`), same reasoning. Routes: `/sales/debit-notes`, `/sales/debit-notes/new`, `/sales/debit-notes/:id/edit`, `/sales/debit-notes/:id`.

- **List page** — mirrors `CreditNoteListPage` exactly: columns Debit Note No, Invoice No (linked), Customer, Reason, Date, Amount, Status. Filters: status, reason, date range, customer.
- **Editor page**:
  - **Invoice selection**: a searchable picker over `submitted` invoices — **no creditable-balance filter** the way Credit Note's picker has one, since there's no ceiling to filter by (§0); any submitted Invoice is eligible.
  - **Two line-entry modes**, matching the two `DebitNoteItem` shapes (§2): "Adjust an existing line" (pick an `InvoiceItem`, enter `qty_adjusted`/rate/amount — used by Under-Billed Invoice and Price Correction) and "Add a new charge" (free-text `description` + `amount` — used by Additional Service Charge and Freight Adjustment). The **Reason** dropdown pre-selects which mode is active and locks out the other, mirroring how Credit Note's reason dropdown re-applies defaults to already-entered lines.
  - **Header tax input**: one optional field, same shape as Invoice's own tax input. No discount input (§2).
  - **Running total**: subtotal (goods + other, shown as one figure or two, designer's call) / tax / total footer — no "exceeds remaining balance" message, since none can ever fire; the only client-side validation is "amount must be greater than zero."
  - **Approval** (only shown at/above the configured threshold, §4): identical UI pattern to what Credit Note's design doc sketched — a "Request Approval" action replacing "Submit" on a draft above the threshold, a pending/rejected banner with the approver's remarks.
  - **Posting**: "Submit" button, same placement as every other module.
- **Detail page**: mirrors `CreditNoteDetailPage` — header fields, linked Invoice (clickable), line table (showing which lines are item-linked vs. freestanding), financial summary, a "Reverse" action, a "Reversed" badge once flagged.
- **History on the Invoice itself**: `InvoiceDetailPage` gains a new **"Debit Notes"** card, structurally identical to its existing **"Credit Notes"** card (same `DataTable` pattern, columns: Debit Note No, Date, Reason, Amount, Status) — sitting alongside "Payment History" and "Credit Notes" so every adjustment to an Invoice is visible in one place.
- **Status display**: reuses `StatusBadge` plus the same secondary "Reversed" `Badge` Credit Note already uses.

---

## 7. Edge Cases

| Case | Behavior |
|---|---|
| **Multiple Debit Notes** (same Invoice, several over time) | Safe by construction, no guard needed — every Debit Note is purely additive, there is no shared running total to over-run (§0's core asymmetry from Credit Note). The real-world risk this creates (nothing stops an arbitrarily large or duplicated charge) is why Approval (§4) is load-bearing here, not decorative. |
| **Already-paid Invoice** | Allowed. `writeUp()` only touches `amount`, never `paid_amount`. The receivable flips from `PAID` back to `PARTIAL`/`UNPAID` (§5) — this is the intended effect, not a side effect to guard against. |
| **Partially-paid Invoice** | Allowed, same mechanism — `paid_amount` untouched, `outstanding_amount` grows by exactly `total_amount`. |
| **Interaction with Credit Notes** | Orthogonal, meet only at the shared `AccountsReceivable.amount` field (§5). A Debit Note after a Credit Note raises the balance Credit Note's own ceiling check validates against; order does not matter, no special-casing in either service. |
| **Interaction with Payment Allocation** | Orthogonal — `amount` (Debit Note) vs. `paid_amount` (Payment Allocation) are separate fields, meeting only through `outstanding_amount` (§5). Zero changes needed to Payment Allocation. |
| **Concurrency** (two Debit Notes racing against the same Invoice) | The risk is a lost update on the increment, not a joint ceiling violation (§4). `submit()` locks the `AccountsReceivable` row (`lockManyForUpdate()`) before `writeUp()`'s read-modify-write — the second of two concurrent submits waits for the first's committed `amount` before computing its own increase, so neither write is lost. Same lock call `CreditNoteService::submit()` already makes, different failure mode being defended against. |
| **Rollback** | `submit()` and `reverse()` are each one `DB::transaction()`. A failure at any step (an inactive Chart of Account during posting, a validation failure after the lock) rolls back every side effect from that call — no partial write-up, no orphaned journal entry. Same "atomicity is structural" pattern as Credit Note. |
| **Reverse** | Only a `SUBMITTED`, not-yet-`is_reversed` note can be reversed. Reversing restores the Invoice's prior balance and posts a swapped-debit/credit journal — the original entry is never mutated. A second `reverse()` call throws, same idempotency shape as `CreditNoteService::reverse()`. |
| **Posted note immutability** | `update()`/`delete()` both require `DRAFT`; a second `submit()` call throws Documentable's standard "only draft documents can be submitted" error — identical to Credit Note, no Debit-Note-specific code needed. |
| **Deleted draft** | `delete()` only permitted in `DRAFT`, soft-deleted, same as every other document. |
| **Tax-only Debit Note followed by a goods-based one** | Independent — `subtotal_goods`/`subtotal_other`/`tax_amount` are tracked per Debit Note, summed only at journal-posting time; no running total needs reconciling across notes since there's no cap to protect (§0). |

---

## 8. Testing Strategy

Same convention as Credit Note: Feature tests against a real service stack on the sqlite in-memory test database, no separate unit-test layer.

- **Feature — happy paths**: Under-Billed Invoice (item-linked, `qty_adjusted > 0`), Price Correction (item-linked, qty 0), Additional Service Charge (freestanding line, no `Item`), Freight Adjustment (freestanding), Tax Adjustment (header-only, only `2100` posts).
- **Feature — journal correctness**: for each reason, assert the posted Journal Entry is balanced, uses the exact accounts from §5 (`4000` for item-linked, `4100` for freestanding, `2100` for tax), and `reference_type === 'debit_note'`.
- **Multiple debit notes**: two sequential Debit Notes against the same Invoice, both succeed, `accounts_receivables.amount`/`debited_amount` reflect the sum of both — no rejection, unlike the equivalent Credit Note test which expects the second/third to eventually fail.
- **Already-paid / partially-paid interactions**: settle an Invoice's AR fully via Payment Allocation, then submit a Debit Note against it — assert `paid_amount` untouched, `amount` increased, resulting `status` flips back to `PARTIAL`/`UNPAID`, `outstanding_amount` positive again.
- **Reverse**: submit then reverse a Debit Note — assert `amount`/`debited_amount` restored, original journal entry untouched with `reversed_by_id` set, reversal journal correctly swapped. Reversing twice throws.
- **Deleted draft**: create a draft, delete it, assert soft-deleted; assert a submitted note cannot be deleted.
- **Posted note immutability**: `update()` on a submitted note throws; a second `submit()` call throws.
- **Rollback**: deactivate a required Chart of Account before `submit()`, assert it throws and nothing committed (`debited_amount` unchanged, no `debit_notes`/`journal_entries` rows); same for `reverse()`.
- **Concurrency**: two sequential (simulating racing) `submit()` calls against the same Invoice's AR row — assert both eventually succeed and `amount` reflects the sum of both (no lost update), the mirror-image assertion of Credit Note's concurrency test (which asserts the *second* correctly fails).
- **Accounting balance**: after a mixed sequence (Invoice → Payment Allocation → Debit Note (freight) → Credit Note (partial) → Reverse the Debit Note), assert the sum of all debits equals the sum of all credits across every posted Journal Entry for that Invoice's chain — same end-to-end proof pattern Credit Note's own test suite already establishes.
- **Approval gating**: a Debit Note at/above the threshold cannot be submitted without an `approved` `ApprovalFlow`; a `rejected` one blocks submission; below the threshold, `submit()` works with no `ApprovalFlow` involved.
- **Line-shape validation**: a line with both `invoice_item_id` and no `description` variations exercised — item-linked-without-snapshot and freestanding-without-description both rejected; a line with `invoice_item_id` set but belonging to a different Invoice rejected (mirrors Credit Note's cross-invoice guard test).

---

## Open Questions

1. **Dedicated `4060 Sales Debit Adjustments` account** (§2/§5) — adopt a separate account for item-linked corrections, or post straight against `4000 Sales Revenue`? No engine difference either way, purely a chart-of-accounts/reporting decision, same category of question as Credit Note's `4050` decision.
2. **Approval threshold value and permission names** — a concrete default and the exact `debit_note.approve` permission need sign-off; §4's mechanism works at any threshold. Worth deciding whether the threshold should be lower than Credit Note's (Debit Notes have no structural ceiling, so the human check carries more weight, §7).
3. **`subtotal_goods` vs `subtotal_other` in the UI** — shown as one combined "Subtotal" figure or two separate lines in the editor/detail footer? No backend impact either way.

Stopping here — no code, no migrations, no tests. Waiting for architectural review.
