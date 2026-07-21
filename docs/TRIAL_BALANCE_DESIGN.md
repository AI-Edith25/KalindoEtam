# Trial Balance — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests until approved.

Frozen inputs this design must not modify: **Accounting Engine, Accounts Receivable, Receipt Entry, Payment Allocation, Credit Note, Debit Note, General Ledger** — every integration point below calls the General Ledger's existing service methods unchanged and adds nothing to `journal_entries`/`journal_entry_lines`/`chart_of_accounts`.

## 0. What already exists (grounding)

Confirmed by inspecting the running app (Chrome — Chart of Accounts, Journal Entries, General Ledger) and reading `GeneralLedgerService`/`GeneralLedgerRepository` before writing anything below:

- **`GeneralLedgerService::listAccounts(array $filters): array`, unchanged, is already almost exactly a Trial Balance.** Confirmed live: the General Ledger List page renders Code/Name/Type/Opening Balance/Debit/Credit/Ending Balance for every account in one unpaginated response — the same 16 rows, the same figures, that a Trial Balance needs as its raw input. This design does not reimplement that computation anywhere; it calls this one method and reshapes its output.
- The return shape is `array<int, array{account: ChartOfAccount, opening_balance: float, debit: float, credit: float, ending_balance: float}>` — `opening_balance`/`ending_balance` are already *signed* (positive for a normal balance, negative for an abnormal one, via `ChartOfAccount::isDebitNormal()`). Trial Balance's job is presentational, not computational: place each row's signed `ending_balance` into a classic Debit or Credit column (§3), never recompute it.
- `ChartOfAccount::isDebitNormal()` (existing, unchanged) is still the only sign-convention logic anywhere in this feature area — reused a third time now (Credit Note/Debit Note's journal routing → General Ledger's `signedMovement()` → Trial Balance's column placement, all reading the same method).
- `GeneralLedgerRepository`'s filters (`date_from`, `date_to`, `status`, `reference_type`, `branch_id`, `company_id`) are already exactly the filters a Trial Balance needs for its own "Date Range"/"Branch"/"Company" requirements — reused as-is, zero new SQL. Confirmed live: Branch/Company still filter to an empty result today, since (per the General Ledger design's own grounding, still true) no business module populates `journal_entry_lines.branch_id` yet. Trial Balance inherits that same "wired, not yet real" status rather than inventing a second, different dormant mechanism.
- `ChartOfAccountRepository::allOrderedByCode()` (existing, unchanged) already returns every account, active or not, unpaginated — confirmed live (16 accounts, no pagination control on the General Ledger List page). Trial Balance's "Account Range" filter (§4) narrows this same already-fetched list in memory; it does not need a new repository method or a new SQL `WHERE code BETWEEN`.
- `JournalEntryService::assertBalanced()` (existing, unchanged) already refuses to post any Journal Entry whose lines don't sum to equal debit/credit totals — confirmed in code and exercised by every existing accounting test in this codebase. This is the reason Trial Balance's "Total Debit == Total Credit" check (§6) is a **structural guarantee**, not routine business validation: it can only ever fail if the underlying data was corrupted outside the Accounting Engine (a direct DB edit, a botched migration), never through normal use of any business module. The design treats it as an integrity check, not a workflow gate.
- No period-closing, fiscal-period-lock, or "period" table exists anywhere in this codebase (already confirmed absent during the General Ledger design and still true) — only `companies.fiscal_year_start`, a single date. "Reporting Period" in this design is therefore a **UI-only concept**: a preset that resolves to `date_from`/`date_to` before calling `GeneralLedgerService::listAccounts()`, never a new backend parameter or table. Period Closing (the Business Goal's named future consumer) is explicitly out of scope here — see Open Questions.
- The General Ledger Account Drill-down page (`/accounting/general-ledger/:accountId`, existing, unchanged) already does everything "Account drill-down" (§5) needs. Trial Balance does not get its own detail page — clicking a Trial Balance row navigates straight to this existing page, carrying the same date range along as query params.

---

## 1. Business Flow

Trial Balance, like General Ledger, is a **read model** — no `Documentable` lifecycle, no Draft/Submitted status, nothing a user creates, edits, or deletes. It answers one question: *"for a chosen reporting period, what is every account's ending balance, laid out the way an accountant expects a trial balance to look?"*

The entire computation is delegated:

```
TrialBalanceService::summarize(filters)
  └─ calls GeneralLedgerService::listAccounts(filters)   ← the ONLY balance computation in this design
       └─ (unchanged) calls GeneralLedgerRepository's aggregate queries over journal_entries/journal_entry_lines
  └─ reshapes each row's already-computed ending_balance into a Debit/Credit column pair (§3)
  └─ sums both columns and flags whether they match (§6)
```

Nothing below this recomputes a balance. `TrialBalanceService` is a thin presentation layer over `GeneralLedgerService`, the same relationship `GeneralLedgerService` itself has to the raw `journal_entries`/`journal_entry_lines` tables — read model built on read model, never a table built on a table.

---

## 2. Data Source

**Chart of Accounts, Journal Entries, Journal Entry Lines — indirectly, entirely through `GeneralLedgerService::listAccounts()`.** No table is queried directly by any new Trial Balance code; `GeneralLedgerRepository` remains the only place SQL against these three tables is written, exactly as the ticket's "reuse only Chart of Accounts / Journal Entries / Journal Entry Lines... reuse the General Ledger calculation logic where appropriate" instruction asks.

```php
class TrialBalanceService
{
    public function __construct(protected GeneralLedgerService $generalLedgerService) {}

    public function summarize(array $filters): array
    {
        $rows = $this->generalLedgerService->listAccounts($filters); // the one and only balance computation
        // ... column placement + totals, §3/§6 — pure PHP over an already-computed array, no new query
    }
}
```

No new migration. No new model. `chart_of_accounts`, `journal_entries`, `journal_entry_lines` are read exactly once, by exactly the same code General Ledger already runs.

---

## 3. Balance Calculation

### Opening Balance, Period Movement, Ending Balance

All three are **already computed** by `GeneralLedgerService::listAccounts()` — `opening_balance`, `debit`/`credit` (the period movement), and `ending_balance` are simply passed through from its return array, untouched. Trial Balance adds no new arithmetic here.

### Normal Balance

`ChartOfAccount::isDebitNormal()` (existing, unchanged) — reused to decide which column an account's ending balance belongs in (below). Trial Balance does not introduce a second way to determine an account's normal side.

### Debit/Credit column placement (the one genuinely new piece of logic)

A classic Trial Balance shows each account's ending balance in *one* of two columns — never as a single signed figure the way the General Ledger List shows it. This mapping is the only new function this design introduces, and it is a pure presentation transform of an already-computed number:

```php
/**
 * Places an already-computed, signed ending_balance (from
 * GeneralLedgerService::listAccounts(), unchanged) into the Debit or
 * Credit column a traditional Trial Balance expects. A positive balance
 * on an account's own normal side is the expected case; a negative one
 * is an abnormal balance (e.g. an overpaid receivable — a real, already-
 * documented scenario from Credit Note's own design, §7) and is placed
 * in the opposite column instead of hidden or clamped to zero.
 */
protected function placeBalance(ChartOfAccount $account, float $endingBalance): array
{
    $isDebitNormal = $account->isDebitNormal();
    $onNormalSide = $endingBalance >= 0;

    return [
        'debit' => $onNormalSide === $isDebitNormal ? abs($endingBalance) : 0.0,
        'credit' => $onNormalSide === $isDebitNormal ? 0.0 : abs($endingBalance),
    ];
}
```

Worked example: Accounts Receivable (debit-normal) with `ending_balance = 235000` → placed entirely in the Debit column (`{debit: 235000, credit: 0}`). If a future Credit Note ever pushed it to `ending_balance = -15000` (overpaid, per the already-documented Credit Note edge case), it would appear in the *Credit* column instead (`{debit: 0, credit: 15000}`) — exactly where an accountant expects an abnormal receivable credit balance to show up, not silently dropped.

### Zero-balance handling

An account with `opening_balance == 0 && debit == 0 && credit == 0 && ending_balance == 0` for the filtered period is a legitimate row (an account nobody posted to this period) — General Ledger's List always shows it (confirmed live, e.g. `1100 Cash and Bank` reads all zeros today). Trial Balance defaults to the **same behavior**, but adds a UI toggle, "Hide zero-balance accounts" (default **on** — the standard accounting-software convention, unlike General Ledger's own list which always shows everything since it's an activity ledger, not a summary report). Toggling it off falls back to exactly what General Ledger already shows. No backend change either way — purely a client-side filter over the already-fetched, already-unpaginated row array (§7).

---

## 4. Filtering

| Filter | Status | Implementation |
|---|---|---|
| **Reporting Period** | **New, UI-only** | A preset dropdown (This Month, This Quarter, This Fiscal Year, Custom Range) that resolves to `date_from`/`date_to` before calling `summarize()`. "This Fiscal Year" reuses the already-existing `companies.fiscal_year_start` (confirmed present, unused by any report today) to compute its boundary — no new backend field. No backend "period" concept is introduced; the API only ever sees plain `date_from`/`date_to`, identical to General Ledger's own contract. |
| **Date Range** | Already exists (General Ledger's `date_from`/`date_to`) | Reused as-is — "Custom Range" in the preset dropdown is just this, exposed directly. |
| **Account Range** | **New, but not "dormant" the way Branch/Company are** | A `code_from`/`code_to` pair filtering the already-fetched, already-unpaginated account list *in the frontend* (`row.account.code >= codeFrom && row.account.code <= codeTo`, plain string comparison — Chart of Account codes are fixed-width numeric strings, e.g. `"1100"`–`"6000"`, confirmed live, so lexicographic and numeric ordering agree). Called "future-ready" in the ticket because its practical value scales with chart-of-account size (16 accounts today, hundreds at a larger company) — but unlike Branch/Company, the underlying data (`chart_of_accounts.code`) is fully populated *today*, so this filter is fully functional immediately, not dormant. No backend change: `TrialBalanceService::summarize()` is never told about it. |
| **Branch** | Already exists (General Ledger's `branch_id`), dormant | Reused as-is — same "wired but nothing populates `journal_entry_lines.branch_id` yet" status already documented for General Ledger (§0), not a new gap. |
| **Company** | Already exists (General Ledger's `company_id`, joins through `branch_id`), dormant | Reused as-is, same status as Branch. |

Every backend-relevant filter above (Reporting Period once resolved to dates, Date Range, Branch, Company, plus `status`/`reference_type` inherited from General Ledger's contract) passes straight through to `GeneralLedgerService::listAccounts($filters)` unchanged — `TrialBalanceService` does not re-validate or re-interpret them.

---

## 5. UI

Placed under `features/accounting/`, a third tab alongside Journal Entries and General Ledger in the existing `accountingSectionNav` (confirmed live — currently two tabs; this design adds a third, same growth pattern every prior sprint's section nav has followed). Route: `/accounting/trial-balance`.

### Trial Balance List (only page this design adds — no detail page, see below)

- `PageHeader` + `ActionBar` (Refresh / disabled Export / disabled Import — same convention as every other list page, including General Ledger's own).
- Filter row: Reporting Period preset, Custom Range (shown when "Custom Range" is selected), Account Range (two small code inputs), Branch, Company, and a "Hide zero-balance accounts" toggle (default on, §3).
- `DataTable`, one row per account (after the zero-balance/account-range filters are applied client-side):

| Code | Name | Type | Debit | Credit |
|---|---|---|---|---|

- Footer row: **Total Debit**, **Total Credit** — bold, same visual weight `CreditNoteDetailPage`/`DebitNoteDetailPage`'s own "Total" footer rows already use.

### Totals

`total_debit = sum(row.debit)`, `total_credit = sum(row.credit)` across the *filtered* (post zero-balance/account-range) row set — computed once in `TrialBalanceService::summarize()` for the unfiltered set (so the backend-computed totals always reflect the true period), and recomputed client-side whenever the zero-balance toggle or Account Range changes the visible subset (so what's on screen always sums to what's displayed, the same "the numbers on screen always reconcile" principle General Ledger's own filter design already established).

### Debit/Credit validation (UI)

A small status pill next to the totals row: **"Balanced"** (green) when `round(total_debit, 2) === round(total_credit, 2)`, or **"Imbalance detected"** (red, with the exact difference shown) otherwise. Per §0/§6, the red state should be unreachable through normal use — it exists as a visible integrity signal, not a routine gate blocking anything (there is no "submit" action here to block).

### Account drill-down to General Ledger

Clicking any row navigates to the **existing, unchanged** `/accounting/general-ledger/:accountId` page (confirmed live — this design adds no new detail page at all), passing the resolved `date_from`/`date_to` as query params so the drill-down opens already scoped to the same reporting period the Trial Balance row was computed for. This is the single largest simplification in this design: Trial Balance's entire "detail" story is "reuse General Ledger's detail page unchanged," not a second implementation of running-balance line lists.

### Export readiness

Same disabled `Export` button every list page in this codebase already ships unwired (Credit Notes, Debit Notes, Chart of Accounts, Journal Entries, General Ledger) — identical placeholder, not built now.

---

## 6. Validation

| Requirement | Behavior |
|---|---|
| **Total Debit == Total Credit** | `round(total_debit, 2) === round(total_credit, 2)`, computed in `TrialBalanceService::summarize()` over every row (before any zero-balance/account-range narrowing) and again client-side over the visible subset when those toggles are active. True by mathematical construction (§0) — `JournalEntryService::assertBalanced()` guarantees every individual posted entry balances, and summing balanced entries is always balanced. |
| **Imbalance detection** | The comparison above, surfaced as the red "Imbalance detected" pill (§5) if it ever fails. Since it's structurally unreachable through the Accounting Engine, this is treated as a data-integrity smoke alarm (evidence of manual DB tampering or a bug elsewhere), not a user-facing error to recover from — no retry/undo action is offered, because there is nothing to undo from the Trial Balance's own read-only perspective. |
| **Empty period** | If every row's `opening_balance`/`debit`/`credit`/`ending_balance` are all zero (no posted activity anywhere in the filtered range), the totals row shows `Rp 0 / Rp 0` — technically "balanced," but the table itself (once zero-balance accounts are hidden, the default) renders the same empty-state message pattern every other list page uses (`emptyMessage="No activity in this period."`), so a genuinely empty period is visually distinct from "everything nets to zero after real activity." |
| **Backdated journals** | No new handling needed — inherited unchanged from `GeneralLedgerService::listAccounts()`, which already recomputes fresh on every call with no caching (General Ledger design §7, still true). A backdated entry posted after a Trial Balance was last viewed simply changes the result on the next fetch. |
| **Reversed journals** | No new handling needed — `listAccounts()` already sums both the original and its reversal (two separate immutable rows, General Ledger design §7) into the same account's period movement; a fully reversed pair nets to zero contribution, exactly as already proven by General Ledger's own `test_ledger_list_sums_to_zero_across_the_whole_chart_of_accounts()`-style test. |
| **Cancelled business documents** | No new handling needed — inherited unchanged. A cancelled Invoice whose Journal Entry was never reversed (the confirmed, documented Accounting Engine gap, General Ledger design §0/§7) still contributes to `listAccounts()`'s totals exactly as it still contributes to the General Ledger; Trial Balance is not positioned to hide or flag it any more than General Ledger already isn't. |

---

## 7. Performance

**Aggregation strategy.** Identical to General Ledger List's own (§0) — `GeneralLedgerService::listAccounts()` already runs exactly two grouped aggregate queries (`openingTotalsByAccount()`, `periodTotalsByAccount()`) regardless of account count, never one query per account. Trial Balance adds zero additional queries; `placeBalance()` and the totals sum are pure PHP over the already-fetched array.

**Pagination.** Not needed, for the same reason General Ledger List doesn't paginate: a Chart of Accounts has dozens of rows at most (16 today), the whole table renders in one response. If a future company's chart genuinely grows into the hundreds, General Ledger List would need to solve this first (it has the identical shape) — Trial Balance would inherit that fix automatically by continuing to call `listAccounts()` unchanged, not by solving pagination twice.

**Index usage.** Fully inherited — the two indexes General Ledger's own migration added (`journal_entry_lines(chart_of_account_id, journal_entry_id)`, `journal_entries(status, posting_date)`) already cover every query this design's single delegated call makes. No new index.

**Large datasets.** The dataset that could actually grow large — the number of *lines* behind each account's aggregate — is already handled by `listAccounts()`'s aggregate-query approach (SUM over an indexed range, not a per-line PHP loop); Trial Balance never touches line-level data at all, only the already-summed per-account totals. The only dataset Trial Balance itself iterates is the account list (dozens), for the client-side zero-balance/account-range filters and the totals sum — trivially cheap at any realistic Chart of Accounts size.

---

## 8. Testing Strategy

Same convention as every prior sprint: Feature tests against a real service stack on the sqlite in-memory test database, reusing the same seeded Invoice→CreditNote→DebitNote chains `GeneralLedgerTest` already establishes rather than re-deriving new fixtures.

- **Unit** — `placeBalance()` in isolation: a debit-normal account with a positive ending balance places entirely in Debit; a credit-normal account with a positive ending balance places entirely in Credit; a debit-normal account with a *negative* ending balance (the overpaid-receivable case) places in Credit instead, and vice versa; a zero ending balance places `{debit: 0, credit: 0}`.
- **Feature — totals and balance** — seed the same mixed Invoice/Credit Note/Debit Note/reversal chain `GeneralLedgerTest::test_ledger_list_sums_to_zero_across_the_whole_chart_of_accounts()` already proves nets to zero at the General Ledger level; assert `TrialBalanceService::summarize()`'s `total_debit === total_credit` and `is_balanced === true` over the same data — the Trial Balance-level restatement of that same proof.
- **Feature — reuse, not duplication** — assert `TrialBalanceService::summarize($filters)`'s per-account `opening_balance`/`ending_balance` are *identical* (not just equal in value, but sourced from the same call) to `GeneralLedgerService::listAccounts($filters)`'s own output for the same filters — the explicit "no duplicate calculation" proof the Architecture Rules ask for.
- **Feature — zero-balance and account-range filtering** — confirm both are pure presentation filters: `summarize()`'s raw row count and totals are unaffected by whatever the frontend later does with them; a dedicated frontend-level (or thin backend helper, if the filtering helper is extracted as a testable pure function) test confirms the code-range comparison correctly includes/excludes boundary accounts (`code_from` and `code_to` both inclusive).
- **Integration** — reporting-period presets: "This Fiscal Year" resolves correctly against a `Company.fiscal_year_start` that isn't January 1st (a company with a non-calendar fiscal year, exercising the one genuinely new date-resolution logic in this design).
- **Edge cases** — empty period (a date range with zero posted activity returns all-zero rows / the empty-state message); backdated journal changes a previously-fetched Trial Balance's totals on refetch (mirroring `GeneralLedgerTest::test_backdated_journal_changes_a_previously_computed_balance_on_recomputation()`); a reversed Credit Note/Debit Note pair contributes net zero to the relevant accounts' Debit/Credit columns; a cancelled Invoice's un-reversed Journal Entry still appears in the totals (mirroring `GeneralLedgerTest::test_cancelled_business_document_still_shows_its_posted_journal()`, restated at the Trial Balance level).

---

## Open Questions

1. **Should `TrialBalanceService::summarize()` exist as a real backend endpoint at all**, given it's a thin wrapper that could instead be pure frontend logic over the already-existing `general-ledger/accounts` response? This design keeps it as a real endpoint specifically because the Business Goal names Trial Balance as the future data source for Profit & Loss, Balance Sheet, and Period Closing — those future modules deserve a stable, purpose-named contract (`/trial-balance`) rather than each independently depending on General Ledger's own endpoint shape. If that reasoning is rejected, the entire backend layer (`TrialBalanceService`/`TrialBalanceController`/route) collapses to zero and Trial Balance becomes a frontend-only page calling `general-ledger/accounts` directly — flagged for explicit confirmation since it changes Phase 1 scope substantially.
2. **Period Closing** (the third named future consumer) will need a genuinely new concept this codebase has never had — a period-lock/fiscal-period table — which Trial Balance itself does not need and does not build. Flagged here only so it isn't assumed to be a side effect of this design; it is a separate, larger future design.
3. **Account Range's exact input shape** — two Chart of Account code text inputs (as sketched in §4/§5) versus two Chart of Account *pickers* (searchable Select, like Journal Entry's own Account filter already is). Text inputs are simpler and match "range," a picker is more consistent with the rest of the app's account-selection UI. No backend impact either way.

Stopping here — no code, no migrations, no tests. Waiting for architectural review.
