# Payment Allocation — Design Document

Status: **Design only — not implemented.** Per the sprint instruction, this document stops before any code. Frozen (per Sprint 11): Chart of Accounts, Journal Entry, Journal Entry Line, `AccountingService`, `JournalEntryService`, automatic posting, manual Journal Entry, journal reversal, Documentable integration, the morph map mechanism, transaction-safe posting. Nothing in this design touches their internals — only their existing public methods (`postForDocument()`, `reverseForDocument()`) are called, exactly as Invoice and Receipt Entry already do.

---

## 0. What already exists — read before designing anything

Before proposing new structure, I re-verified what Sprint 6 + Sprint 10 + Sprint 11 already built, because most of "a customer may pay one or many invoices, an invoice may be paid by one or many payments" is **already true today**:

- `ReceiptEntry` (the Payment header — frontend calls it "Incoming Payment") already has many `ReceiptEntryItem` rows, each pointing at a different `AccountsReceivable` (≈ Invoice) — **one payment paying many invoices already works.**
- Many separate `ReceiptEntry` documents can each carry a `ReceiptEntryItem` against the **same** `AccountsReceivable` row over time (`AccountsReceivableService::settle()` accumulates `paid_amount`) — **one invoice paid by many payments already works.**
- `receipt_entries.total_amount` has an existing column comment: *"Cache only, derived from receipt_entry_items"* — confirming today's real constraint: **the amount received and the amount allocated are the same number, always, by construction.** You cannot receive $500 and allocate $300 now, $200 later. You cannot receive more than the invoices being paid right now can absorb — `ReceiptEntryService::assertWithinOutstanding()` **throws** on any overpayment attempt.
- Allocation only happens at `ReceiptEntry` creation time; once submitted (posted), the document is immutable — there is no way to *add* an allocation to an already-posted payment.

**So the actual gap this sprint closes is narrower than "build multi-invoice/multi-payment support" — it's:**
1. Decouple *receiving* money from *applying* money, so a payment can be posted before every rupiah is assigned to an invoice.
2. Support genuine overpayment (money received now, applicable to an invoice later — this customer's, not a write-off).
3. Give "allocation" its own first-class, reusable, single mechanism — not an implicit side-effect of `ReceiptEntry::create()`.

Everything below is designed to add exactly that, reusing the existing Invoice/AR/Payment/Accounting layers untouched wherever possible.

---

## 1. Business Flow

```
Customer sends money
        ↓
Payment received (ReceiptEntry) — amount is a fact, independent of any invoice
        ↓
Payment posted → Dr Cash/Bank, Cr Unapplied Customer Payments (full amount)
        ↓
Allocation — apply some/all of the payment's unallocated balance to one or more Invoices
        ↓
Each allocation → Dr Unapplied Customer Payments, Cr Accounts Receivable (this invoice)
        ↓
Invoice's AccountsReceivable.paid_amount increases → status: Unpaid → Partially Paid → Paid
        ↓
Payment's own unallocated_amount decreases accordingly
```

**Remaining Balance** exists on *two* sides at once, and the design must never confuse them:
- **Invoice's remaining balance** = `AccountsReceivable.amount − paid_amount` (already exists, untouched).
- **Payment's remaining balance** = `ReceiptEntry.total_amount − Σ(active allocations)` — **new.**

**Partial Payment** (an invoice not fully paid by one allocation) — already fully supported, unchanged.

**Overpayment** — a payment allocates less than its `total_amount` across the invoices it's applied to; the difference stays as the payment's own `unallocated_amount`, sitting in the **Unapplied Customer Payments** account until a *future* invoice (same customer) is allocated against it. It is never forced onto one invoice, and it never produces a negative-outstanding or negative-payment state on the Invoice side — the Invoice's own status machine (`unpaid/partially_paid/paid`) is completely untouched by this design.

---

## 2. Database Design

**One new table, zero new columns anywhere else.** No changes to `invoices`, `accounts_receivables`, `chart_of_accounts`, `journal_entries`, or `journal_entry_lines`.

### `receipt_entries` — two column additions (migration, not a new table)

| Column | Type | Why |
|---|---|---|
| `allocated_amount` | `decimal(15,2) default 0` | Cache, "derived from payment_allocations" — same convention as `total_amount`'s existing comment. Updated transactionally inside `PaymentAllocationService` alongside every allocate/reverse. |
| *(`total_amount`'s comment changes)* | — | From *"Cache only, derived from receipt_entry_items"* to *"Direct user input — the amount actually received, independent of allocation."* Purely a comment/behavior change, no column-type change. |

`unallocated_amount` is **not** stored — it's `total_amount − allocated_amount`, computed in the Resource layer exactly like `AccountsReceivable.outstanding_amount` already is (`$this->amount - $this->paid_amount`, no stored column). One more reuse of an established convention.

### `payment_allocations` (new table — evolves `receipt_entry_items`)

`receipt_entry_items` already **is** a payment-to-invoice allocation record; renaming it to what it actually represents, rather than building a second parallel table, is the direct application of "avoid duplicated business logic." Migration: `Schema::rename('receipt_entry_items', 'payment_allocations')`, then `ALTER` for the new/renamed columns below. Model class `ReceiptEntryItem` → `PaymentAllocation`.

| Column | Type | Explanation |
|---|---|---|
| `id` | `uuid` PK | Standard. |
| `receipt_entry_id` | `uuid` FK → `receipt_entries`, `cascadeOnDelete()` | The Payment this allocation draws from. Unchanged from today's `receipt_entry_id`. |
| `accounts_receivable_id` | `uuid` FK → `accounts_receivables`, `restrictOnDelete()` | The Invoice (via its AR row) this allocation pays. Unchanged from today's column. |
| `allocated_amount` | `decimal(15,2)` | Renamed from `received_amount` — the old name conflated "money received" with "money applied"; this table only ever represents the latter now that they're decoupled. |
| `allocation_date` | `date` | **New.** Distinct from `receipt_entries.receipt_date` — an allocation can happen days after the money was received (the whole point of decoupling). Defaults to "today" when created via the Allocation dialog. |
| `is_reversed` | `boolean default false` | **New.** Set by `PaymentAllocationService::reverse()`. Kept as a flag rather than relying solely on soft-delete, because a reversed allocation must remain visible in the Payment/Invoice history (audit trail), not disappear — soft-delete would hide it from default queries. |
| audit + timestamps + soft-delete | — | Unchanged, standard. |

No `journal_entry_id` column — exactly like `Invoice` and `ReceiptEntry` today, the link to the posted `JournalEntry` is the existing polymorphic `reference_type`/`reference_id` pair on `journal_entries`, queried via the same `JournalEntryRepository::findActivePostedByReference()` already built in Sprint 11. Storing a second, redundant FK here would be exactly the kind of duplicated relationship the ticket warns against.

### Chart of Accounts — one new seed row, no schema change

```
1150  Unapplied Customer Payments   liability
```
Classified as a liability (money received that isn't yet earned/applied — a real obligation, either to apply it to a future invoice or refund it), inserted into the existing 1000s numbering block used by `ChartOfAccountsSeeder`. No new `AccountType` case, no new columns — `ChartOfAccountsSeeder` gets one more array row.

### Morph map — one new line

```php
'payment_allocation' => \App\Models\PaymentAllocation::class,
```
Added to the existing `Relation::morphMap()` call in `AppServiceProvider::boot()` (Sprint 11). This is the mechanism the frozen Accounting Engine already promises — "future modules register new reference types without modifying the engine" — being exercised exactly as designed. `ReceiptEntry`'s existing `'receipt_entry'` mapping is untouched; each `PaymentAllocation` gets its **own** journal entry (see §5), so it needs its own reference type to be independently reversible.

---

## 3. Domain Model

Four entities, each with one job — the explicit anti-"God Object" requirement is met by keeping `ReceiptEntry` (the fact that money arrived) and `PaymentAllocation` (the fact that some of it was applied) as separate models with separate services, rather than growing `ReceiptEntry`/`ReceiptEntryItem` into something that also decides how money gets used.

| Entity | Owns | Does NOT own |
|---|---|---|
| **Invoice** (unchanged) | Its own line items, its own totals | Payment tracking (delegates entirely to AR, as already designed in Sprint 10) |
| **AccountsReceivable** (unchanged) | `amount`, `paid_amount`, `status` — the single source of truth for "how much of this invoice is paid" | *How* it got paid, *when*, or *from which payment* — that's `PaymentAllocation`'s job |
| **ReceiptEntry / Payment** (extended) | The fact that a specific amount arrived from a customer on a specific date via a specific method; its own Documentable lifecycle (draft/posted/cancelled); its own `total_amount`/`allocated_amount` | Which invoices it pays — zero knowledge of Invoice/AR beyond the `customer_id` it's scoped to |
| **PaymentAllocation** (new, evolves `ReceiptEntryItem`) | The fact that a specific amount of a specific Payment was applied to a specific Invoice's receivable, on a specific date, reversible on its own | Journal posting mechanics (delegates to `AccountingService`), AR balance mutation (delegates to `AccountsReceivableService`) |

**Relationships:**
```
Customer 1──* Invoice 1──1 AccountsReceivable 1──* PaymentAllocation *──1 ReceiptEntry *──1 Customer
                                                        │                        │
                                                        └── JournalEntry (via reference, per allocation)
                                                                                 └── JournalEntry (via reference, the receipt itself)
```
The two `1──*` arms out of `AccountsReceivable` and `ReceiptEntry` into `PaymentAllocation` are exactly "one invoice, many payments" and "one payment, many invoices" — both requirements satisfied by one join-style table, not two.

---

## 4. Service Layer

Four services, three of them already exist. Transaction boundaries are marked explicitly since that's a named requirement.

### `PaymentAllocationService` (new — the single mechanism, per the sprint's explicit requirement)

```php
allocateBatch(ReceiptEntry $payment, array $lines): Collection   // [{accounts_receivable_id, amount}, ...]
reverse(PaymentAllocation $allocation): void
unallocatedBalance(ReceiptEntry $payment): float
```
- `allocateBatch()` — **one `DB::transaction()`.** `lockForUpdate()` the `ReceiptEntry` row and every targeted `AccountsReceivable` row up front (same locking pattern `NamingSeriesRepository::lockDefaultForType()` already uses — reused convention, not invented). For each line: guard payment is `SUBMITTED` (can't allocate unposted money), guard `accountsReceivable.customer_id === payment.customer_id` (reused check, currently inline in `ReceiptEntryService::addLine()`, moved here), guard `amount <= payment's remaining unallocated_amount` (new), guard `amount <= AR's outstanding` (reused, same math `assertWithinOutstanding` already does). Then: create the `PaymentAllocation` row, call `AccountsReceivableService::settle()` (**reused, unchanged**), call `AccountingService::postForDocument($allocation, [...], ...)` (**reused, unchanged**) with reference type `'payment_allocation'`. Finally increments `ReceiptEntry.allocated_amount`. This is the **only** place in the codebase that ever creates a `PaymentAllocation` row — including the "allocate at the same time as recording the payment" convenience path, which routes through this same method (see `ReceiptEntryService::submit()` below).
- `reverse()` — **one `DB::transaction()`.** Guards not already reversed. Calls `AccountsReceivableService::unsettle()` (new, symmetric to `settle()`), calls `AccountingService::reverseForDocument($allocation)` (**reused, unchanged** — the exact mechanism built in Sprint 11), sets `is_reversed = true`, decrements `ReceiptEntry.allocated_amount` (returning the balance to "unallocated," so it can be reallocated elsewhere — this is the correction path for a misapplied payment, mirroring the Accounting Engine's own "reverse, never edit" philosophy).

### `ReceiptEntryService` (extended, not rewritten)

```php
create(array $data): ReceiptEntry          // total_amount now direct input, no items required
submit(ReceiptEntry $payment, array $allocations = []): ReceiptEntry
update(), delete()                          // unchanged, still draft-only
```
- `create()` — `total_amount` becomes a plain validated input field (today it's derived by summing items; that summation logic is deleted, not duplicated elsewhere). Items/allocations are no longer accepted here at all — allocation is always a separate act, even when it happens in the same user gesture.
- `submit(ReceiptEntry $payment, array $allocations = [])` — **one `DB::transaction()`** wrapping: `$payment->submit()` (Documentable, unchanged), `AccountingService::postForDocument($payment, [Dr cash/bank account, Cr 1150], ..., $payment->payment_date)` for the **full** `total_amount` (reusing the existing `cashAccountCode()` payment-method mapping from Sprint 11 unchanged), then — if `$allocations` is non-empty — one call to `PaymentAllocationService::allocateBatch()`. This single transaction is why the "record payment and allocate it immediately" UX (today's behavior) and "receive now, allocate later" (the new capability) are the *same code path* with an optional argument, not two implementations.

### `AccountsReceivableService` (one new method, symmetric to the existing one)

```php
settle(AccountsReceivable $ar, float $amount): AccountsReceivable    // unchanged
unsettle(AccountsReceivable $ar, float $amount): AccountsReceivable  // new
```
`unsettle()` is `settle()` with subtraction instead of addition, reusing the exact same `SettlementStatus::resolve()` helper (`app/Support/SettlementStatus.php`) that already exists precisely because it's shared between AR and AP — no new status logic anywhere.

### `AccountingService` / `JournalEntryService` — **no changes.** Called via their existing public methods only.

---

## 5. Accounting Integration

**Two-leg model, always — no conditional branching on "was this allocated immediately or later."** This is the core design decision, so the exact journals:

**At Payment posting** (`ReceiptEntryService::submit()`), for the full `total_amount`, regardless of how much (if any) is allocated in the same call:
```
Dr  Cash and Bank (1100, or per cashAccountCode())      total_amount
Cr  Unapplied Customer Payments (1150)                  total_amount
```

**At each allocation** (`PaymentAllocationService::allocateBatch()`, one journal entry *per line*, each independently reversible):
```
Dr  Unapplied Customer Payments (1150)                  allocated_amount
Cr  Accounts Receivable (1200)                           allocated_amount
```

**Why not one combined `Dr Cash / Cr AR` journal when allocation happens immediately (today's common case)?** Because that would require the posting logic to branch on "is this fully allocated right now or not," and produce two different journal shapes for what the ledger should treat as one predictable pattern. The two-leg version costs one extra (always-balanced, trivially reversible) journal entry per fully-immediate payment, in exchange for **overpayment, partial allocation, and later allocation all working through the exact same mechanism with zero special cases** — directly what "single payment allocation mechanism used everywhere" asks for. This is presented as a recommendation, not a foregone conclusion — see the question in the closing section.

**Partial payment effect on journals:** unchanged from today — each allocation posts its own `Dr 1150 / Cr 1200` for whatever amount is applied; the AR's `paid_amount` accumulates exactly as `settle()` already does.

**Overpayment behavior:** the receipt-side journal always posts the full amount into `1150`. Allocations only ever move money *out* of `1150` into specific invoices' AR. Money that's never allocated simply stays parked in `1150` — visible on a future Trial Balance as a real, correct liability balance, not hidden or force-applied anywhere.

**Reversals:** `PaymentAllocationService::reverse()` calls the existing `AccountingService::reverseForDocument()` unchanged — it finds the allocation's own posted journal (via `reference_type='payment_allocation'`) and posts the swapped-debit/credit reversal, exactly like Invoice's Sprint 11 integration point already does for its own journal. Reversing an *allocation* never touches the Payment's own receipt journal (that money was still received); reversing the Payment itself (e.g., a bounced/NSF check) is a real future need but explicitly **out of scope** for this design — noted under §7 as a related-but-separate capability.

---

## 6. UI Flow

- **Payment (Incoming Payment) Detail page** (extends the existing page): adds an "Unallocated Amount" stat, replaces the current single flat item list with an **Allocations** table (Invoice, Date, Amount, status, a per-row "Reverse" action), and an **Allocate** button — visible whenever `unallocated_amount > 0` and the payment is posted.
- **Invoice Detail page** (extends the existing "Payment History" section from Sprint 10 — no structural change needed, it already renders `accountsReceivable.receiptEntryItems`, which becomes `accountsReceivable.paymentAllocations` under the rename): unaffected beyond the relation rename; optionally gains a "Receive Payment" shortcut that opens the Allocation dialog pre-scoped to this one invoice and this customer's payments with a balance.
- **Allocation Dialog** (new, a Drawer — matching the app's existing focused-action pattern, not a full page): shows the Payment's unallocated balance at the top; lists the customer's outstanding invoices (from `AccountsReceivable` where `status != paid`) each with an amount input capped client-side at `min(invoice outstanding, remaining unallocated in this dialog)`; a running "remaining to allocate" total; disables Confirm until every entered amount is valid and non-negative. Submits **one** `allocateBatch()` call for every non-zero line — presented to the user as one action, atomic under the hood.
- **Status display:** the Payment's badge gains a derived label — `Draft` / `Posted · Unallocated` / `Posted · Partially Allocated` / `Posted · Fully Allocated` — computed the same way Invoice's Sprint 10 `display_status` is derived (from stored fields, never a stored status value), reusing the pattern, not the code.
- **Validation surfaced in the UI:** allocation amount > invoice outstanding, allocation amount > payment's unallocated balance, allocating to a different customer's invoice (shouldn't be selectable at all — the invoice list in the dialog is pre-filtered server-side to the payment's own customer) — all mirrored client-side for immediate feedback, enforced server-side as the real guard (same "client convenience, server authority" split used everywhere else in this app).

---

## 7. Edge Cases

| Case | Behavior |
|---|---|
| Partial payment | Unchanged — AR status `partially_paid`, already works. |
| Multiple invoices (1 payment → N invoices) | `allocateBatch()` with N lines, one journal + one AR settle per line, all in one transaction. |
| Multiple payments (N payments → 1 invoice) | Each payment's own `allocateBatch()` call against the same AR row; `settle()`'s existing accumulation (already proven since Sprint 6) handles it unchanged. |
| Overpayment | Excess stays as the payment's `unallocated_amount`, parked in `1150`, allocatable to any future invoice for the same customer. Never forced, never blocked at the payment-receipt step (only individual allocation lines are capped by invoice outstanding). |
| Underpayment | Same as partial payment — no special case. |
| Deleted draft payment | No allocations possible before posting (guarded), so existing `assertDraft()` + soft-delete on `ReceiptEntryService::delete()` remains correct unchanged. |
| Posted payment | Immutable header (existing guard, unchanged); its allocations can each be reversed individually without touching the receipt journal. |
| Concurrency (two allocations racing the same AR or the same payment's balance) | `lockForUpdate()` on both the `ReceiptEntry` and every targeted `AccountsReceivable` row inside `allocateBatch()`'s transaction, before re-validating balances — same row-locking convention `NamingSeriesRepository` already established. |
| Double submit | `Documentable::submit()`'s existing `abort_if(status !== DRAFT)` already prevents this at the application layer; double-click protection is the existing frontend `disabled={mutation.isPending}` convention, reused. |
| Rollback | Every guard failure inside `allocateBatch()`/`submit()` throws before commit; the whole transaction — journal, AR settle, allocation rows, cache columns — rolls back together, same "atomicity is structural" proof pattern as Sprint 11's rollback tests. |

---

## 8. Testing Strategy

This codebase's actual established convention (confirmed by inspection — there is no separate Unit-test layer; every prior sprint's tests are Feature tests hitting real services against an in-memory sqlite DB) is followed rather than introducing a testing pyramid this project doesn't use elsewhere. New `tests/Feature/PaymentAllocationTest.php`, same fixture pattern as `AccountingEngineTest`/`InvoiceWorkflowTest`:

- **Happy paths:** one payment → one invoice; one payment → many invoices (`allocateBatch`); one invoice ← many payments (accumulating across separate `ReceiptEntry`s); immediate allocation via `submit($payment, $allocations)` in one call.
- **Overpayment:** payment larger than the invoice it's first applied to — assert the remainder stays on `unallocated_amount`, then gets allocated to a *second* invoice for the same customer in a later call.
- **Failure cases:** allocate to a different customer's invoice (blocked); allocate more than invoice outstanding (blocked, reused guard); allocate more than the payment's unallocated balance (blocked, new guard); allocate against a draft (unposted) payment (blocked).
- **Reversal:** reversing an allocation restores the invoice's outstanding, restores the payment's unallocated balance, and posts a correctly swapped-and-linked reversal journal (same assertions as Sprint 11's `reverse()` test, applied to `PaymentAllocation`).
- **Rollback verification:** force a mid-batch failure (e.g., the second line in a batch exceeds its invoice's outstanding) and assert *nothing* from the batch committed — not even the first, otherwise-valid line — mirroring Sprint 11's `test_invoice_submission_rolls_back_completely_if_journal_posting_fails` pattern exactly.
- **Concurrency:** two sequential (simulating racing) `allocateBatch()` calls against an AR row with only enough outstanding for one of them — assert the second correctly fails after the lock is released rather than double-applying.

---

## Open questions for you before implementation

1. **The two-leg `1150` suspense-account model (§5)** is the recommendation, but it's a real behavior change to `ReceiptEntry::submit()`'s journal shape for the *already-working* immediate-allocation case, not just an addition. Confirm you want this over a cheaper alternative (post `Dr Cash/Cr AR` directly when every line is allocated in the same call, only using `1150` when something is left over) — the cheaper alternative has less ledger noise but reintroduces exactly the "two different journal shapes for the same action" branching this design avoids.
2. **Renaming `ReceiptEntryItem`→`PaymentAllocation` and `receipt_entry_items`→`payment_allocations`** touches the frontend's `ReceiptEntryItem` type and every place that reads `line.accounts_receivable` (Sprint 10/CR-001 code). Confirm the rename is wanted now rather than introducing a new table alongside the old one.
3. Full **Payment reversal** (the receipt-side leg itself — e.g., a bounced check) is noted as a natural extension of `AccountingService::reverseForDocument()` but is explicitly not designed here. Confirm that's correctly out of scope for this sprint.
