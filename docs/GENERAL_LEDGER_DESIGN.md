# General Ledger — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests until approved.

Frozen inputs this design must not modify: **Accounting Engine, Accounts Receivable, Receipt Entry, Payment Allocation, Credit Note, Debit Note** — the General Ledger is additive only: new read endpoints, new UI pages, zero changes to `journal_entries`, `journal_entry_lines`, `JournalEntryService`, or any business module's `journalLines()`.

## 0. What already exists (grounding)

Confirmed by inspecting the running app (Chrome) and reading the code before writing anything below:

- **The Journal Entry List page's own description already says it**: *"The General Ledger — every posted debit/credit, system-generated or manually posted."* This sprint gives that claim a real, dedicated view — today it's just a page subtitle over a flat list of whole entries.
- `journal_entries` (`id`, `document_number`, `status`, `posting_date`, `reference_type`, `reference_id`, `total_debit`, `total_credit`, `reverses_id`, `reversed_by_id`) and `journal_entry_lines` (`id`, `journal_entry_id`, `chart_of_account_id`, `branch_id` nullable, `debit`, `credit`, `description`) already hold everything a ledger needs. No new accounting table is proposed anywhere in this design — the two tables above are the *only* source of truth read from.
- `ChartOfAccount::isDebitNormal(): bool` already exists (`app/Models/ChartOfAccount.php`) — `true` for Asset/Expense, `false` for Liability/Equity/Revenue. This is the exact function a running-balance calculation needs to decide whether a debit increases or decreases the balance, and it requires zero changes.
- `JournalEntryRepository::search()` already supports an `account_id` filter (`whereHas('lines', ...)`) — confirmed live in the Journal Entry List UI's "Account" dropdown. This is the closest existing precedent, and also exposes exactly why it isn't a ledger: it returns whole multi-account Journal Entries that happen to touch the filtered account, with every other line on those entries still attached, no per-account debit/credit isolation, and no running or ending balance. The General Ledger is what that filter is missing.
- `journal_entry_lines.branch_id` already exists as a nullable FK — but confirmed by grep that **no business module ever sets it**: `Invoice::journalLines()`, `CreditNote::journalLines()`, `DebitNote::journalLines()` all return plain `{account, type, amount}` arrays with no branch. The column is schema-ready, not data-ready. Branch filtering in this design is built to use it when present and degrade gracefully when it's null — see §4.
- `branches.company_id` exists (branches belong to companies), but neither `chart_of_accounts` nor `journal_entries`/`journal_entry_lines` carry a `company_id` anywhere. Chart of Accounts is effectively company-global today. Company filtering can only ever be as real as branch filtering (join `journal_entry_lines.branch_id → branches.company_id`) — it inherits the same "wired but dormant" status, not a separate gap. See §4/Open Questions.
- `JournalEntry::cancel()` unconditionally throws (confirmed in code and matches Credit Note/Debit Note's own precedent) — a posted Journal Entry is corrected only by `JournalEntryService::reverse()`, which posts a **new**, separate entry and links `reverses_id`/`reversed_by_id`. The original is never mutated, never netted away. A reversed entry and its reversal are **two distinct immutable rows** the ledger must both show — never a single collapsed "0" line. See §7.
- **A confirmed, pre-existing edge case**: `InvoiceService::cancel()`'s own docblock states it *"does NOT reverse any posted Journal Entry for this Invoice."* An Invoice can move from `SUBMITTED` to `CANCELLED` (when unpaid) while its original Journal Entry stays posted, untouched. The General Ledger will faithfully show that entry — it is not the Ledger's job to fix this (Accounting Engine is frozen), only to not hide it. See §7.
- No period-closing / lock mechanism exists anywhere in this codebase. There is no `is_closed` flag, no fiscal-period table beyond `companies.fiscal_year_start`. Future period closing is explicitly out of scope for this design — flagged, not built. See §7.
- The UI's existing "General Ledger"-adjacent surface (Journal Entry List/Detail, Chart of Accounts list, the Reports module's `Sales`/`Purchase`/etc. report pages) all share one visual vocabulary, confirmed live: `PageHeader` (title + description + count + `ActionBar` with Refresh/Export/Import), a `FilterPanel` row of `Select`/`Input[type=date]` filters above a `DataTable`, `Pagination` below it, `StatusBadge` for status pills, and — on Chart of Accounts specifically — clicking a row opens a right-side `DetailDrawerLayout` panel rather than navigating to a new page. The Reports pages additionally show one or two plain `Card`-based stat tiles (a label + a large number) above the filter row. This design reuses every one of these components; §5 names which page uses which.

---

## 1. Business Flow

The General Ledger is **not a new business document**. It has no `Documentable` lifecycle, no Draft/Submitted status, nothing a user "creates." It is a **read model**: a query surface over the same immutable `journal_entries`/`journal_entry_lines` rows every other module already writes, re-sliced per Chart of Account instead of per transaction.

Two views, both pure reads:

1. **Ledger List** — one row per Chart of Account, for a chosen date range: opening balance, total debit, total credit, ending balance. This is the "trial-balance-shaped" overview — literally the first building block of a future Trial Balance (§ Business Goal).
2. **Ledger Detail (Account Drill-down)** — pick one account from the list, see every posted `journal_entry_line` that touches it inside the date range, in posting order, with a running balance column that starts at that account's opening balance and ends at its ending balance.

Nothing here writes. `GeneralLedgerService` (new, this design's only new service) has exactly one job — translate a set of filters into a SQL aggregate/query against the two existing tables — and no `create`/`update`/`delete`/`submit` methods at all, unlike every business-document service this codebase has built so far. This asymmetry from Credit Note/Debit Note is deliberate and is the core architectural fact of this design.

---

## 2. Data Source

**Only `journal_entries` and `journal_entry_lines`, joined to `chart_of_accounts`. No new accounting table.**

```sql
-- The one shape every ledger query in this design reduces to:
SELECT jel.*, je.posting_date, je.status, je.reference_type, je.reference_id, je.document_number
FROM journal_entry_lines jel
JOIN journal_entries je ON je.id = jel.journal_entry_id
WHERE jel.chart_of_account_id = :account_id
  AND je.status = 'submitted'          -- only posted entries are real money, see below
  AND je.deleted_at IS NULL
  AND jel.deleted_at IS NULL
ORDER BY je.posting_date ASC, je.document_number ASC, jel.id ASC
```

**Only `status = 'submitted'` entries count.** A `draft` Journal Entry (the manual "New Journal Entry" flow lets one be saved without posting) has never affected any real balance — `AccountsReceivable`, `AccountsReceivable.credited_amount`/`debited_amount`, none of it move until `JournalEntryService::post()` runs. Including drafts in a ledger balance would show money that was never actually posted. `status` stays available as a **filter** (§4) so a user can explicitly ask "show me unposted drafts too," but it defaults to `submitted`-only, and only `submitted`-only entries ever contribute to the opening/running/ending balance math in §3.

Every number in this design is computed at query time from these two tables. Nothing is cached to a new column, nothing is denormalized. This is the literal meaning of "never duplicate accounting data" from the ticket — it is also just following the same "cache columns are for things routinely re-derived, never a second source of truth" discipline `credited_amount`/`debited_amount` on `AccountsReceivable` already established, except here there isn't even a cache: the Ledger recomputes every time, because unlike a single Invoice's own balance, the Ledger has no one owning document to keep a cache in sync with a `submit()`/`reverse()` call — it aggregates across every module that has ever posted, which will keep growing.

---

## 3. Ledger Posting Logic

Nothing is "posted" by the Ledger — this section is really "balance derivation logic," reusing the ticket's heading. Every figure below is a pure function of already-posted rows, computed fresh on every request.

### Sign convention

`ChartOfAccount::isDebitNormal()` (existing, unchanged) decides the sign:

```php
// Debit-normal (Asset, Expense): balance increases on debit, decreases on credit.
// Credit-normal (Liability, Equity, Revenue): balance increases on credit, decreases on debit.
$signedMovement = $account->isDebitNormal()
    ? (float) $line->debit - (float) $line->credit
    : (float) $line->credit - (float) $line->debit;
```

This one ternary is the entire "posting logic" — reused identically for opening balance, each line's running balance, and the ending balance. No account-type-specific branching anywhere else.

### Opening Balance

The signed sum of every posted, non-deleted line for that account **before** the requested date range's start:

```sql
SELECT
  COALESCE(SUM(jel.debit), 0) AS total_debit,
  COALESCE(SUM(jel.credit), 0) AS total_credit
FROM journal_entry_lines jel
JOIN journal_entries je ON je.id = jel.journal_entry_id
WHERE jel.chart_of_account_id = :account_id
  AND je.status = 'submitted'
  AND je.posting_date < :range_start
```

One indexed aggregate query (§6) — never a loop over every historical line in PHP. `opening_balance = isDebitNormal ? total_debit - total_credit : total_credit - total_debit`. If no `date_from` filter is given, opening balance is `0` and the range is "since the beginning" — same convention every other list page in this codebase uses for an unset date filter.

### Running Balance

Computed once per request, in one pass over the already-ordered line query from §2, starting from the opening balance:

```php
$runningBalance = $openingBalance;
foreach ($lines as $line) {
    $runningBalance += $signedMovement($account, $line);
    $line->running_balance = $runningBalance; // attached for the response, never persisted
}
```

Deterministic ordering is what makes this well-defined: `posting_date ASC, document_number ASC, id ASC`. `posting_date` alone is not unique (many entries can share a date — confirmed live: three of the six seeded entries in this environment share `18 Jul 2026`), so `document_number` (monotonically assigned by the same `NamingSeriesRepository` sequence every other document type uses) is the deterministic tiebreaker, with the line's own `id` as a final tiebreaker for multiple lines within one entry.

### Debit / Credit (per line)

Shown as-is from `journal_entry_lines.debit`/`.credit` — no transformation. A line is always single-sided (`JournalEntryService::assertEachLineIsSingleSided()`, unchanged, still enforced at write time), so the Ledger detail table's Debit/Credit columns are exactly those two stored fields, one of them always zero.

### Ending Balance

Simply the running balance after the last line in range — equivalently, `opening_balance + (isDebitNormal ? sum(debit) - sum(credit) : sum(credit) - sum(debit))` over the in-range lines. Both are computed (the loop already produces it; a direct SUM is used for the **Ledger List**'s per-account summary row, where the full line list is never fetched — see §6).

---

## 4. Filtering

| Filter | Status | Implementation |
|---|---|---|
| **Account** | Already exists (`JournalEntryRepository::search()`'s `account_id`) | Reused as-is for the Ledger List's account picker / the Detail page's implicit account. |
| **Date Range** | Already exists (`date_from`/`date_to` on Journal Entry List) | Reused as-is — drives opening balance (§3) and which lines appear. |
| **Reference Type** | Already exists | Reused as-is — filters lines to only those whose parent Journal Entry has a given `reference_type` (`invoice`, `credit_note`, `debit_note`, `receipt_entry`, `payment_allocation`, or null for a manual entry). |
| **Document Status** | Already exists (`status` on Journal Entry List) | Reused as-is, defaulting to `submitted` per §2 rather than "all statuses" — the one default change from the existing Journal Entry List's own default. |
| **Reference Number** | **New** | A `reference_number` filter matching `journal_entries.document_number` — wait, no: matching the *reference document's* number (`INV-00001`, `CN-00001`, `DBN-00001`), not the Journal Entry's own `JE-00006`. Requires joining through the polymorphic `referenceDocument` relation (already defined on `JournalEntry`, unchanged) filtered by its `document_number`, or — cheaper and avoiding a polymorphic join per reference type — matching against `JournalEntryResource`'s already-computed `reference_document_number` shape client-side isn't an option for a DB-level filter, so this is a small, additive `whereHas('referenceDocument', fn ($q) => $q->where('document_number', 'like', "%{$search}%"))` on the existing `morphTo`. No schema change. |
| **Branch** | **New, future-ready per the ticket, but dormant per §0** | `branch_id` filter on `journal_entry_lines.branch_id`. Since no module populates it yet, this filter will show *all* lines when left unset and will correctly return *zero* lines for any specific branch today — it is built now so the day a module starts setting `branch_id`, filtering works with no Ledger code change, exactly the "future-ready" ask. Not defaulted to a specific branch; defaults to unfiltered. |
| **Company** | **New, future-ready, piggybacks on Branch** | No `company_id` column exists or is proposed on `journal_entries`/`journal_entry_lines` (§0) — adding one now would be inventing a fact the rest of the schema doesn't have yet. Instead, a `company_id` filter is implemented as `whereHas('branch', fn ($q) => $q->where('company_id', $companyId))`, riding the same dormant `branch_id` column. Same "wired, not yet real" status as Branch — flagged together, not a separate mechanism. |

All seven filters are read by one `IndexGeneralLedgerRequest` (new), validated the same way `IndexJournalEntryRequest` already validates its subset — `sometimes|nullable`, `Rule::enum(DocumentStatus::class)` for status, `uuid|exists:...` for the three ID filters. No filter is required; every one of them composes independently, same `->when($filters[...] ?? null, ...)` chain style already used by every `*Repository::search()` in this codebase.

---

## 5. UI

Placed under `features/accounting/`, alongside Journal Entry — a new **"General Ledger"** entry in `Accounting`'s section nav (mirroring how `salesSectionNav` grew a tab per new document type). Routes: `/accounting/general-ledger`, `/accounting/general-ledger/:accountId`.

### Ledger List (`GeneralLedgerListPage`)

Mirrors the Reports module's `Sales Report` page shape (§0) more than the Journal Entry List: a `PageHeader`, a filter row (`FilterPanel` with Date Range / Reference Type / Status / Branch / Company selects — no Account filter here, since Account *is* the row), then a `DataTable` with one row per Chart of Account:

| Code | Name | Type | Opening Balance | Debit | Credit | Ending Balance |
|---|---|---|---|---|---|---|

Clicking a row navigates to that account's Detail page (not a `DetailDrawerLayout` side panel like Chart of Accounts' own list — a Ledger account's transaction history is a full page, not a summary drawer). No stat tiles at the top for v1 (a "Total Assets" / "Total Liabilities" card row is a natural Trial-Balance-adjacent addition, explicitly deferred to the Trial Balance module the Business Goal names, not duplicated here — YAGNI).

### Ledger Detail / Account Drill-down (`GeneralLedgerDetailPage`)

Header: account code/name/type (reuses the same `DetailField`/`DetailSection` pattern `CreditNoteDetailPage`/`DebitNoteDetailPage` already use for their own header cards), plus the resolved Opening Balance / Ending Balance for the active date range as two prominent figures.

Body: a `DataTable`, one row per `journal_entry_line`:

| Date | Journal No | Reference | Description | Debit | Credit | Running Balance |
|---|---|---|---|---|---|---|

- **Journal No** links to `/accounting/journal-entries/:id` (existing page, unchanged).
- **Reference** reuses `resolveJournalReferenceLink()` (existing, unchanged — already resolves `Invoice`/`Credit Note`/`Debit Note`/`Receipt Entry` labels to their detail pages) so a ledger line for a Debit Note is one click from its own DBN-00001 detail page, same as today's Journal Entry Detail page.
- Filter row above the table: Date Range, Reference Type, Reference Number, Document Status, Branch (Company omitted here — a single account's ledger is already branch/company-scoped by definition once that data exists; Company stays a Ledger-List-only filter).

### Export readiness

Every list page in this codebase already renders a disabled `Export` button in its `ActionBar` (confirmed live — Credit Notes, Debit Notes, Chart of Accounts, Journal Entries all show it, none wired up yet). The Ledger List and Detail pages get the same disabled button for consistency, wired up in a later sprint exactly like every other module's — not a new pattern, not built now.

---

## 6. Performance

**Indexes.** `journal_entry_lines` currently has no index beyond its primary key and FK constraints (confirmed by reading its migration, §0) — every Ledger query filters by `chart_of_account_id` and range-scans by the joined `journal_entries.posting_date`. One new composite index is needed:

```php
// New migration, additive only — no existing index touched:
Schema::table('journal_entry_lines', function (Blueprint $table) {
    $table->index(['chart_of_account_id', 'journal_entry_id']); // supports the account_id join + per-entry line lookups
});
Schema::table('journal_entries', function (Blueprint $table) {
    $table->index(['status', 'posting_date']); // supports the WHERE status = 'submitted' AND posting_date range on every ledger query
});
```

`journal_entries` already has an index on `(reference_type, reference_id)` — reused as-is for the Reference Type/Number filters.

**Large datasets.** Two different scales, two different strategies:
- **Ledger List** (one row per Chart of Account, ~16-50 accounts even at enterprise scale) — no pagination needed, the whole table renders in one response. Each row's opening/debit/credit/ending is one aggregate SQL query per account, run as a single query with `GROUP BY chart_of_account_id` rather than N+1 queries per row — the same "one query, not a loop" discipline `AccountsReceivableRepository::outstandingSummary()` already uses for its own aggregate.
- **Ledger Detail** (transaction lines for one account, potentially thousands over a company's lifetime) — standard offset pagination via Laravel's `paginate()`, identical contract to every other list endpoint in this codebase (`per_page`, `meta.current_page`/`total`/`last_page`). The one wrinkle: running balance (§3) requires knowing the *cumulative* position, which an arbitrary page N's `LIMIT`/`OFFSET` alone doesn't give you. Resolved by computing the opening balance for the filtered range once (§3's aggregate query), then **the running balance for page N is computed starting from a second aggregate query for "everything before this page's first row"**, not by re-summing every prior page in PHP — i.e., opening balance for page N = opening balance for the whole range + signed sum of every line strictly before page N's first line. Still one or two aggregate queries per page request, never an unbounded scan.

**Running balance strategy — chosen deliberately, alternatives noted:**
- **Chosen (v1): compute in PHP, one ordered pass per page**, as in §3. Simple, correct, needs no database-specific feature, matches this codebase's existing "compute in the service layer" style everywhere else (`SettlementStatus::resolve()`, `CreditNoteService`'s remaining-balance math, all plain PHP over query results).
- **Considered, deferred**: a SQL window function (`SUM(...) OVER (ORDER BY posting_date)`) would let the database compute running balance in one query with no PHP loop. Not used for v1 — it's a real optimization once ledger volumes are large enough to matter, but adding a DB-specific SQL feature for a problem the current data volume (dozens of entries, confirmed live) doesn't yet have is exactly the kind of premature optimization this codebase's own design discipline (see Credit Note's explicit "no COGS, no inventory-reversal voucher — flagged, not built" precedent) argues against. Flagged as the first thing to revisit if a real customer's ledger gets slow.
- **Rejected**: persisting a running-balance column anywhere. This is the literal "duplicate accounting data" the ticket forbids — a running balance is only ever correct relative to a specific filtered view (a date range, possibly a branch), so persisting one bakes in a view that the next filter change invalidates. Always derived, per §2.

---

## 7. Edge Cases

| Case | Behavior |
|---|---|
| **Backdated journals** (a Journal Entry posted today with a `posting_date` in the past) | Handled with zero special-casing — every Ledger query is always freshly derived from `journal_entries.posting_date`, never from *when* a row was inserted. A backdated entry simply changes the result of any future query whose range includes that date, exactly as if it had always been there. The only visible effect: a previously-viewed Ledger Detail page, if reloaded, now shows a different opening/running/ending balance than it did before — expected and correct, not a bug, since nothing was ever cached (§2/§6). |
| **Reversed journals** | The original entry and its reversal (§0 — `reverses_id`/`reversed_by_id`, two separate immutable rows) **both appear as separate lines** in every affected account's ledger, on their own posting dates, each contributing its own signed movement to the running balance. The Ledger never nets them into one row or hides the original once reversed — doing so would make the ledger stop being an audit trail. A future UI polish (not built now) could visually pair a reversal with what it reverses using the already-available `reverses_id`/`reversed_by_id` fields, same way Journal Entry Detail already shows "Reverses: JE-00004" / "Reversed by: JE-00005" (confirmed live). |
| **Voided business documents** (the confirmed pre-existing gap, §0 — a cancelled Invoice whose Journal Entry was never reversed) | The Ledger shows that Journal Entry's lines exactly as posted — it has no way to know, and no mandate, to second-guess a still-`submitted`, not-`reversed_by`-anything entry just because its source Invoice later moved to `cancelled`. This is a known, pre-existing gap in the *Accounting Engine's* integration with Invoice cancellation (documented in `InvoiceService::cancel()`'s own docblock before this sprint), not something the Ledger introduces or is positioned to fix — the Accounting Engine is frozen. The one thing the Ledger *can* do cheaply: since `reference_document_number`/`reference_type` are already resolved per line (§5), a future UI enhancement could show a small "source cancelled" indicator by checking the loaded `referenceDocument`'s current status — flagged as a nice-to-have, not required for this design to be complete, and explicitly not built now. |
| **Future period closing** | No period-closing/locking mechanism exists anywhere in this codebase today (§0) — there is nothing to interact with. This design's Ledger always reflects the live, current state of `journal_entries`/`journal_entry_lines` with no concept of a "closed" period blocking a backdated entry or freezing a balance. Out of scope, flagged for a future module exactly like Credit Note's Approval Workflow and Debit Note's inventory/COGS gaps were flagged rather than built. |
| **An account with zero activity in range** | Opening = Ending = the account's balance carried in from before the range (possibly `0` for a never-used account), Debit/Credit totals both `0`, Ledger Detail shows an empty line list with the existing `DataTable` empty-state message pattern (`emptyMessage="No transactions in this period."`, same convention as every other list page's empty state). |
| **An inactive Chart of Account** (`is_active = false`) | Still appears in the Ledger List and remains drill-down-able — deactivating an account (`ChartOfAccountService`, unchanged) only blocks *future* postings against it (`AccountingService::resolveLines()`'s existing `findActiveByCode()` guard), it does not and must not hide historical activity. Confirmed this is exactly the same reasoning Credit Note's rollback tests already exercise (deactivating `4050` to prove a submit-time failure) — inactive-but-historical is a normal, expected state, not an edge case to hide. |

---

## 8. Testing Strategy

Same convention as every prior sprint: Feature tests against a real service stack on the sqlite in-memory test database, no separate unit-test layer, no mocking of the Accounting Engine.

- **Opening balance correctness**: seed a sequence of posted Journal Entries touching one account across several dates, request the Ledger for a range starting partway through, assert the opening balance equals the signed sum of everything strictly before the range — for both a debit-normal (Asset/Expense) and a credit-normal (Liability/Equity/Revenue) account, since the sign flips.
- **Running balance correctness**: assert each line's running balance in the Detail response equals opening balance plus the cumulative signed sum up to and including that line, in the exact `posting_date, document_number, id` order §3 specifies — including a same-`posting_date` tie to prove the tiebreaker is deterministic (rerun the same request twice, assert byte-identical ordering).
- **Ending balance correctness**: assert it equals both (a) the last line's running balance and (b) opening balance plus the range's total signed movement, computed two different ways to catch a drift bug between the List page's SUM-based figure and the Detail page's loop-based figure.
- **Only posted entries count**: create a `draft` Journal Entry (never `post()`ed) touching an account, assert it contributes nothing to opening/running/ending balance by default, then assert it *does* appear when the Status filter explicitly includes `draft`.
- **Reversed journals both appear**: submit a Credit Note (posts a Journal Entry), reverse it (posts a second, swapped one — reusing the existing `CreditNoteService`/`DebitNoteService` chains this codebase already tests), assert both entries' lines appear as separate rows in the affected accounts' ledgers, and assert the net signed movement across both is exactly zero (the account's balance ends where it started) — the ledger-level equivalent of the existing `test_accounting_stays_balanced_across_a_full_invoice_credit_note_reverse_chain()` assertion, reused as the pattern.
- **Backdated entry recomputation**: fetch a Ledger Detail response, then post a new Journal Entry with a `posting_date` inside the already-fetched range, fetch again, assert the second response's opening/running/ending balances differ from the first by exactly the backdated entry's signed movement.
- **Filters**: each of the seven filters in §4 independently narrows the result set correctly; Reference Number filter specifically matches on the *reference document's* number (`INV-00001`), not the Journal Entry's own (`JE-00001`) — a test asserting the two are distinguishable.
- **Branch/Company filters against dormant data**: with `branch_id` null on every seeded line (today's real-world default, §0), assert a Branch or Company filter returns an empty result set rather than erroring or silently ignoring the filter — proving the filter is real, not decorative, even though nothing currently populates the field it filters on.
- **Pagination + running balance across pages**: seed enough lines on one account to span 3+ pages at a small `per_page`, assert page 2's first line's running balance correctly accounts for every line on page 1 (§6's "opening balance for page N" strategy), without fetching page 1 first.
- **Inactive account still shows history**: deactivate a Chart of Account that has historical postings, assert its Ledger Detail is unchanged and still reachable.
- **Cross-module coverage**: one end-to-end test posting through Invoice → Payment Allocation → Credit Note → Debit Note → assorted reversals (reusing existing test setup helpers from `CreditNoteTest`/`DebitNoteTest`), then asserting the Ledger List's sum of every account's ending balance nets to zero (assets + expenses on one side, liabilities + equity + revenue on the other, by construction of double-entry) — the first real "does the whole ledger balance" proof this codebase will have, directly reusable as the seed of a future Trial Balance test.

---

## Open Questions

1. **Branch/Company filters are real but permanently empty until a business module starts setting `journal_entry_lines.branch_id`** (§0/§4) — is that acceptable to ship now (future-ready, zero functional value today), or should Branch/Company be left out of this sprint's filter row entirely and re-added once a module actually populates the field? No engine difference either way, purely a "ship a dormant filter vs. not" call.
2. **Reference Number filter's join cost** (§4) — `whereHas('referenceDocument', ...)` is a polymorphic query across up to five different tables (`invoices`, `credit_notes`, `debit_notes`, `receipt_entries`, `payment_allocations`). At today's data volume this is fine; if it ever needs to be faster, a denormalized `reference_document_number` column cached onto `journal_entries` at posting time (mirroring `credit_notes.customer_id`'s own "denormalized for query convenience" precedent) is the natural follow-up — not built now, flagged as the answer if Open Question 2 becomes a real performance question later.
3. **Window-function running balance** (§6) — confirmed deferred, revisit once real data volume justifies it.
4. **Should the Ledger List's per-account rows link out to a future Trial Balance page once one exists**, or does Trial Balance simply reuse `GeneralLedgerService`'s same aggregate query directly? Leaning toward the latter (Trial Balance is this design's List view with zero new balance logic, just a different presentation), flagged for whoever scopes Sprint 15B+.

Stopping here — no code, no migrations, no tests. Waiting for architectural review.
