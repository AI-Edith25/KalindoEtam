# Balance Sheet — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests, no frontend pages until approved.

Frozen inputs this design must not modify: **Accounting Engine, Accounts Receivable, Receipt Entry, Payment Allocation, Credit Note, Debit Note, General Ledger, Trial Balance, Profit & Loss** — every integration point below calls existing service methods unchanged and adds nothing to `journal_entries`/`journal_entry_lines`/`chart_of_accounts`. `report_account_mappings` (existing table, Sprint 17B) gains new rows for a new `statement_type` value only — no migration.

## 0. What already exists (grounding)

Confirmed by inspecting the running app (Chrome — Journal Entries, General Ledger, Trial Balance, Profit & Loss, all under "Accounting Reports") and reading `GeneralLedgerService`/`TrialBalanceService`/`ProfitLossService`/`ReportAccountMapping`/`ChartOfAccount`/`ChartOfAccountsSeeder`/`ReportAccountMappingSeeder` before writing anything below:

- **The chart has 16 accounts, confirmed live via General Ledger (unfiltered)**: three Assets (`1100` Cash and Bank, `1200` Accounts Receivable, `1300` Inventory — all seeded, none marked current vs. non-current anywhere), four Liabilities (`1150` Unapplied Customer Payments, `2000` Accounts Payable, `2100` Tax Payable, `2200` Accrued Expenses), two Equity (`3000` Owner's Equity, `3100` Retained Earnings), plus the three Revenue and four Expense accounts Profit & Loss already classifies.
- **`1150 Unapplied Customer Payments` is `account_type = liability` despite its `1xxx` code** — confirmed in `ChartOfAccountsSeeder.php`. This is the same class of proof `PROFIT_LOSS_DESIGN.md §4` already used for `4900 Discount Given` (an `expense`-typed account with a `4xxx`/revenue-looking code): **code ranges cannot be trusted to imply section**, here even more directly, since `1150` would misclassify as an Asset under any naive "first digit" rule. This is the concrete proof, from this exact chart, that Balance Sheet needs the same `ReportAccountMapping` mechanism P&L needed — not a second one.
- **No account distinguishes Current vs. Non-Current (Assets) or Current vs. Long-Term (Liabilities)** — `AccountType` only has five values (`asset`/`liability`/`equity`/`revenue`/`expense`); it was never meant to carry this distinction, the same gap `PROFIT_LOSS_DESIGN.md §4` already documented for Revenue vs. Other Income.
- **`Owner's Equity` (`3000`) and `Retained Earnings` (`3100`) both show `Rp 0`, confirmed live** (Trial Balance, zero-balance toggle off, This Month) — nothing has ever posted to either account. No module in this codebase posts to Equity today.
- **No period-closing exists** — confirmed again in `PROFIT_LOSS_DESIGN.md §0`/Open Question 2 and `TRIAL_BALANCE_DESIGN.md` Open Question 2, still true. Revenue and Expense accounts accumulate forever; nothing ever zeroes them into Retained Earnings at fiscal year-end. This is the central problem §6 below has to solve.
- **`GeneralLedgerService::listAccounts(array $filters)`, unchanged, already computes a true cumulative `ending_balance` per account when `date_from` is omitted** — confirmed by reading `GeneralLedgerRepository::openingTotalsByAccount()` (returns `[]`, i.e. opening = 0, whenever `date_from` is unset) and `periodTotalsByAccount()` (sums everything when unbounded). This is exactly what a Balance Sheet needs: unlike Profit & Loss (which must discard `ending_balance` and use period movement only, per `PROFIT_LOSS_DESIGN.md §3`), a Balance Sheet **is** the cumulative-balance report General Ledger and Trial Balance already produce — it just needs Asset/Liability/Equity accounts grouped into sections, the same job `ReportAccountMapping` already does for Revenue/Expense.
- **`ProfitLossService::summarize(array $filters)`, unchanged, already computes a correctly-netted net profit for any date range**, including the contra-account sign-flip fix from Sprint 17B (`ProfitLossSection::isDebitNormal()`). Balance Sheet reuses this wholesale (§5) rather than re-deriving Revenue/Expense arithmetic a second time.
- **`Company.fiscal_year_start` (existing, unchanged) has had zero backend consumers until now** — confirmed via grep; every existing use (`resolvePeriodPreset`/`resolveFiscalYearStart` in `trialBalanceFilters.ts`/`profitLossFilters.ts`) is frontend-only, a UI preset default. Balance Sheet is the first design that needs this value **server-side**, because `BalanceSheetService` itself (not just the filter bar) needs to know where "this fiscal year" starts, relative to whatever `as_of_date` was requested (§5/§6). This is new but small: reading an existing column server-side for the first time, not a new concept.
- **Accounting Reports already has four tabs** (Journal Entries, General Ledger, Trial Balance, Profit & Loss) in `accountingSectionNav` — confirmed live. This design adds Balance Sheet as a fifth tab, the same growth pattern the section has followed four times already.

---

## 1. Architecture Design

Balance Sheet, like every other report in Accounting Reports, is a **read model** — no `Documentable` lifecycle, nothing created/edited/deleted. It answers: *"as of one specific date, what does the business own, owe, and what is it worth?"*

```
BalanceSheetService::summarize(filters)
  └─ resolves filters.as_of_date → { date_to: as_of_date }  (date_from deliberately omitted — §7)
  └─ calls GeneralLedgerService::listAccounts({date_to, branch_id, company_id, ...})
       └─ (unchanged) the SAME single call General Ledger/Trial Balance/P&L already make
  └─ calls ReportAccountMappingRepository::forStatementType(BALANCE_SHEET)  ← one new lookup, same repository, new statement_type value only
  └─ groups each mapped Asset/Liability/Equity account's ending_balance (never periodMovement — §4) by BalanceSheetSection
  └─ resolves the fiscal year containing as_of_date, via Company.fiscal_year_start (§6)
  └─ calls ProfitLossService::summarize({date_from: fiscal_year_start, date_to: as_of_date, ...})   → Current Year Profit (§6)
  └─ calls ProfitLossService::summarize({date_from: null, date_to: fiscal_year_start - 1 day, ...})  → prior-years' cumulative profit, folded into Retained Earnings (§7)
  └─ sums Assets / Liabilities / Equity, derives Total Assets, Total Liabilities, Total Equity, Total Liabilities + Equity
```

`BalanceSheetService` sits at exactly the same layer `TrialBalanceService` and `ProfitLossService` already occupy — a thin presentation layer over `GeneralLedgerService`. The one architectural step beyond precedent: it also calls `ProfitLossService` (a service calling a service), which is not a new pattern — `ProfitLossService` already calls `GeneralLedgerService` the same way. Balance Sheet is simply one layer further down the same stack:

```
GeneralLedgerService  (raw balances)
        ▲
        │
TrialBalanceService  ProfitLossService  (reports built directly on the raw balances)
        ▲                    ▲
        │                    │
        └──────── BalanceSheetService ────────┘
        (reuses BOTH — a cumulative-balance report for Assets/Liabilities/Equity,
         plus P&L's own net-profit computation for Current Year Profit / Retained Earnings)
```

No new balance computation exists anywhere in this design. Every number on the report traces back to `GeneralLedgerRepository`'s two aggregate queries, called (indirectly) either once (via `listAccounts`) or twice more (via the two `ProfitLossService::summarize()` calls, each of which itself calls `listAccounts` once).

---

## 2. Service Reuse Strategy

| Existing service/method | Reused for | Reused unchanged? |
|---|---|---|
| `GeneralLedgerService::listAccounts(array $filters)` | Every Asset/Liability/Equity account's cumulative `ending_balance` as of `as_of_date` | Yes, zero changes |
| `ChartOfAccount::isDebitNormal()` | Sign convention — same primitive General Ledger/Trial Balance/P&L already use | Yes, zero changes |
| `ReportAccountMappingRepository::forStatementType(ReportStatementType $type)` | Section classification lookup — already generic over `$type`, needs no change to accept `BALANCE_SHEET` | Yes, zero changes |
| `ProfitLossService::summarize(array $filters)` | **Current Year Profit** and **prior-years' Retained Earnings**, called twice with two different date ranges | Yes, zero changes — called, not modified |
| `Company.fiscal_year_start` | Anchoring "which fiscal year contains `as_of_date`" | Yes, zero changes — first server-side read of an existing column |
| Trial Balance's zero-balance toggle / "Hide zero-balance accounts" UI pattern | Balance Sheet is a complete-chart cumulative report like Trial Balance, not a period-movement report like P&L — same default-on toggle, reused as-is | Yes, identical UI behavior |

**New, report-specific logic** (the only things this design adds):

1. **`BalanceSheetService`** itself — constructor-injects `GeneralLedgerService`, `ReportAccountMappingRepository`, and `ProfitLossService`. Same shape `ProfitLossService` already has, one dependency deeper.
2. **`BalanceSheetSection` enum** — six cases (§4), the Balance Sheet's own classification vocabulary, exactly mirroring `ProfitLossSection`'s shape (including its own `isDebitNormal()` for contra-account safety — §4).
3. **`ReportStatementType::BALANCE_SHEET`** — one new enum case. `report_account_mappings` needs no migration; the table already supports multiple statement types per its original design (`PROFIT_LOSS_DESIGN.md §4`: *"new reports... add a new case here plus their own mapping rows — never a new chart_of_accounts column"*).
4. **Fiscal-year anchoring, ported server-side** — given `as_of_date` (+ optional `company_id`), resolve the fiscal year start that contains it. Same math the frontend's `resolveFiscalYearStart` already does, now needed by the backend itself because `BalanceSheetService` — not just a filter default — needs this boundary to split Current Year Profit from Retained Earnings (§6/§7).
5. **Section subtotal + grand total arithmetic** — pure arithmetic over already-fetched data, no new query, same discipline as every derived row in P&L §5.

No new repository, no new migration, no new balance-computation logic — only classification (enum + mapping rows) and arithmetic.

---

## 3. Report Structure

```
Assets
  Current Assets                          sum(ending_balance, section = current_asset)
  Non-Current Assets                      sum(ending_balance, section = non_current_asset)
Total Assets                              = Current Assets + Non-Current Assets

Liabilities
  Current Liabilities                     sum(ending_balance, section = current_liability)
  Long-Term Liabilities                   sum(ending_balance, section = long_term_liability)
Total Liabilities                         = Current Liabilities + Long-Term Liabilities

Equity
  Share Capital                           sum(ending_balance, section = share_capital)
  Retained Earnings                       mapped-account balance + prior-years' P&L (§7)
  Current Year Profit                     ProfitLossService net_profit for the current fiscal year to date (§6) — NOT a mapped section
Total Equity                              = Share Capital + Retained Earnings + Current Year Profit

Total Liabilities and Equity              = Total Liabilities + Total Equity   (must equal Total Assets — §8)
```

Every subtotal and grand total is pure arithmetic over already-fetched section sums — never a separate query, the same discipline `PROFIT_LOSS_DESIGN.md §5` already established.

### Concrete mapping against the current 16-account chart

| Code | Name | `account_type` | Balance Sheet section |
|---|---|---|---|
| `1100` | Cash and Bank | asset | Current Asset |
| `1200` | Accounts Receivable | asset | Current Asset |
| `1300` | Inventory | asset | Current Asset |
| `1150` | Unapplied Customer Payments | liability | Current Liability |
| `2000` | Accounts Payable | liability | Current Liability |
| `2100` | Tax Payable | liability | Current Liability |
| `2200` | Accrued Expenses | liability | Current Liability |
| `3000` | Owner's Equity | equity | Share Capital |
| `3100` | Retained Earnings | equity | Retained Earnings |

Non-Current Assets and Long-Term Liabilities have no seeded account today — both sections render empty, the same legitimate-empty-section handling P&L's "Other Expenses: Rp 0" already established (§8).

---

## 4. ReportAccountMapping Strategy

Same table, same repository method, same principle as `PROFIT_LOSS_DESIGN.md §4`: classification lives in `report_account_mappings`, never in `chart_of_accounts`, because a single account (e.g. `1200 Accounts Receivable`) may need a *different* grouping under different reports — and because, as `1150`'s code proves (§0), the classification cannot be safely derived from `code` ranges.

```php
enum BalanceSheetSection: string
{
    case CURRENT_ASSET = 'current_asset';
    case NON_CURRENT_ASSET = 'non_current_asset';
    case CURRENT_LIABILITY = 'current_liability';
    case LONG_TERM_LIABILITY = 'long_term_liability';
    case SHARE_CAPITAL = 'share_capital';
    case RETAINED_EARNINGS = 'retained_earnings';

    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::CURRENT_ASSET, self::NON_CURRENT_ASSET => true,
            self::CURRENT_LIABILITY, self::LONG_TERM_LIABILITY,
            self::SHARE_CAPITAL, self::RETAINED_EARNINGS => false,
        };
    }
}
```

`isDebitNormal()` is carried over from `ProfitLossSection` unchanged in shape — no contra-asset account (e.g. Accumulated Depreciation) is seeded today, so every mapped account currently agrees with its section's convention, but the mechanism is reused, not reinvented, for the day one is added (the exact scenario Sprint 17B already had to solve for `4900 Discount Given`).

`ReportStatementType` gains one case:

```php
enum ReportStatementType: string
{
    case PROFIT_LOSS = 'profit_loss';
    case BALANCE_SHEET = 'balance_sheet';
}
```

`ReportAccountMappingRepository::forStatementType()` needs no change — it was already written generic over `ReportStatementType` (`PROFIT_LOSS_DESIGN.md §4`). A new `ReportAccountMappingSeeder`-style declarative list (code → `BalanceSheetSection`) seeds the nine rows in the table above, same skip-and-log-if-missing resilience the existing seeder already has.

**No new `chart_of_accounts` column, no schema change.** An Asset/Liability/Equity account with no `balance_sheet` mapping row simply never appears on this report — same "the report only shows what belongs on it" principle, with the same `Log::warning()` visibility net `ProfitLossService::warnAboutUnmappedAccounts()` already provides, mirrored for Balance Sheet's own three account types.

---

## 5. Current Year Profit Strategy

**Determination: Current Year Profit is never a mapped section — it is injected into Equity as a derived figure from a second service call, the same way Gross Profit/Operating Income/Net Profit are already derived figures in `ProfitLossService`'s own response rather than mapped sections.**

```php
$fiscalYearStart = $this->resolveFiscalYearStart($asOfDate, $filters['company_id'] ?? null);

$currentYearProfit = $this->profitLossService->summarize([
    'date_from' => $fiscalYearStart,
    'date_to' => $asOfDate,
    'branch_id' => $filters['branch_id'] ?? null,
    'company_id' => $filters['company_id'] ?? null,
])['net_profit'];
```

This is a direct call to the existing, unchanged `ProfitLossService::summarize()` — it re-runs P&L's own `listAccounts()` + mapping + section/contra-sign logic internally, but `BalanceSheetService` never touches that logic itself. **Zero duplication**: if P&L's net-profit formula ever changes (e.g. Tax stops being `null`), Balance Sheet's Current Year Profit changes with it automatically, for free.

`resolveFiscalYearStart($asOfDate, $companyId)` is the one genuinely new piece of logic (§2 item 4) — given `as_of_date`, walk `Company.fiscal_year_start`'s month/day forward or backward by whole years until it lands on or before `as_of_date`, the same anchoring math `resolveFiscalYearStart` already does client-side in `trialBalanceFilters.ts`/`profitLossFilters.ts`, just parameterized by `as_of_date` instead of "now" (necessary because the user can pick a past `as_of_date`, and Current Year Profit must mean *that* date's fiscal year, not today's).

---

## 6. Retained Earnings Strategy

This is the sprint's real design problem, already flagged as future work by two prior designs (`PROFIT_LOSS_DESIGN.md` Open Question 2, `TRIAL_BALANCE_DESIGN.md` Open Question 2): **there is no period-closing, so `3100 Retained Earnings` has never received a posting and reads `Rp 0` forever** (confirmed live, §0). If Retained Earnings were read as just that account's `ending_balance`, the Balance Sheet would only balance for a company in its very first fiscal year — any prior year's earnings would still be sitting inside Revenue/Expense accounts' own ever-accumulating balances, invisible to a Balance Sheet (which never includes Revenue/Expense accounts at all), and Total Assets would silently exceed Total Liabilities + Equity by exactly that missing amount.

**Design: until Period Closing exists, Retained Earnings is a computed figure, not a stored balance — the mapped account's real balance (today always `0`) plus every prior fiscal year's Profit & Loss net profit, derived the same way Current Year Profit is derived, just pointed at the date range *before* the current fiscal year:**

```php
$mappedRetainedEarnings = $sections[BalanceSheetSection::RETAINED_EARNINGS->value]['subtotal']; // ending_balance of account 3100 — today always 0

$priorYearsProfit = $this->profitLossService->summarize([
    'date_to' => $fiscalYearStart->subDay()->toDateString(), // date_from omitted — unbounded back to inception
    'branch_id' => $filters['branch_id'] ?? null,
    'company_id' => $filters['company_id'] ?? null,
])['net_profit'];

$retainedEarnings = round($mappedRetainedEarnings + $priorYearsProfit, 2);
```

Because `ProfitLossService::summarize()`'s period-movement sum over two contiguous, non-overlapping ranges — `[inception, fiscalYearStart − 1 day]` and `[fiscalYearStart, as_of_date]` — partitions every journal entry line by its own `posting_date` into exactly one of the two, **Retained Earnings + Current Year Profit together always equal the true cumulative net income since inception**, without either figure double-counting or omitting any period. This is the mechanism that makes §8's balancing guarantee hold, and it costs zero new balance logic — it's the identical `ProfitLossService::summarize()` call as §6, called a second time with a different (and, notably, unbounded-from) date range, exactly the way `PROFIT_LOSS_DESIGN.md §6` already established `date_from` as an ordinary filter passed straight through.

**Retained Earnings and Current Year Profit stay visibly separate on the report** (§3/§9) — never merged into one figure — so a future Period Closing sprint has a clean seam: once real closing journal entries begin crediting/debiting `3100` at each fiscal year-end, `$mappedRetainedEarnings` starts reflecting real postings, and `$priorYearsProfit`'s date range should stop reaching further back than the last closing date (otherwise it would double-count years already closed into `3100`). That transition is explicitly **out of scope for this design** — flagged here as the concrete migration path, not built now, the same "flagged, not solved" discipline the two prior designs already used for this exact gap.

---

## 7. Filtering

| Filter | Status | Mandatory? |
|---|---|---|
| **As Of Date** (single date, not a range) | New — resolves to `date_to` only; `date_from` is deliberately never sent | **Yes**, defaults to Today, same "no way to clear it" discipline P&L already applies to its own mandatory `date_from` |
| **Branch** | Reused as-is (General Ledger's `branch_id`), same dormant status already documented in every prior design | No |
| **Company** | Reused as-is (General Ledger's `company_id`), same dormant status — additionally used server-side to resolve `fiscal_year_start` (§5/§6) | No |

**Why a single date, not a range (contrast with Trial Balance and Profit & Loss):** a Balance Sheet is a *point-in-time snapshot* — "what exists right now" — not a period. This is the mirror image of P&L's own reasoning (`PROFIT_LOSS_DESIGN.md §3`/§6): P&L needs a bounded `date_from` because an unbounded range would wrongly cumulate every prior period's income under "this period's" label; Balance Sheet needs the **opposite** — `date_from` must stay unbounded, because cumulative-since-inception is exactly what a snapshot of Assets/Liabilities/Equity requires. Internally, `as_of_date` maps to `GeneralLedgerService::listAccounts()`'s `date_to` only, letting `ending_balance` be the true cumulative figure §0 already confirmed `listAccounts()` produces when `date_from` is omitted.

No Reporting Period preset dropdown (This Month/This Quarter/This Fiscal Year) — those describe a *range*, which has no meaning for a snapshot. The filter bar is a single date input (default Today), simpler than every other report's filter bar in this module.

---

## 8. Report Behavior

- **Calculation flow**: `BalanceSheetService::summarize($filters)` → one `listAccounts({date_to: as_of_date, ...})` call + one `ReportAccountMappingRepository::forStatementType(BALANCE_SHEET)` call → group into six sections by `ending_balance` (never `periodMovement` — Balance Sheet is cumulative, unlike P&L) → two `ProfitLossService::summarize()` calls for Current Year Profit / Retained Earnings's prior-years component (§5/§6) → sum sections into subtotals → derive Total Assets, Total Liabilities, Total Equity, Total Liabilities + Equity.
- **Ordering**: sections render in the fixed order shown in §3 (Assets: Current → Non-Current; Liabilities: Current → Long-Term; Equity: Share Capital → Retained Earnings → Current Year Profit); accounts within a section render by `code` ascending, same as every other report.
- **Subtotals/grand totals**: all derived arithmetic, never re-queried.
- **Zero-balance handling**: reuses Trial Balance's own "Hide zero-balance accounts" toggle (default on), not P&L's "omit zero-movement lines with no toggle" behavior — a Balance Sheet, like Trial Balance, is a complete-chart cumulative report where a zero balance is still a meaningful, potentially-audited fact about an account, not a periodic income-statement line that conventionally omits inactivity.
- **Account visibility**: an Asset/Liability/Equity account with no `balance_sheet` mapping row is omitted (with a `Log::warning()`, §4) — same principle as every other report.
- **Sign conventions**: `ChartOfAccount::isDebitNormal()` (Asset accounts debit-normal, Liability/Equity accounts credit-normal) is reused unchanged; `BalanceSheetSection::isDebitNormal()` (§4) protects against a future contra account the same way `ProfitLossSection` already does. Every figure displays as its natural positive balance — Assets add into Total Assets, Liabilities/Equity add into Total Liabilities + Equity — no on-screen negatives except a genuine abnormal balance, handled identically to Trial Balance's own abnormal-balance display.
- **Guaranteeing Total Assets = Total Liabilities + Equity**: by double-entry construction, every journal entry balances, so summed across the *entire* chart (all 16 accounts, unbounded date range), all `ending_balance`s net to zero. Rearranged: `Total Assets − Total Liabilities − (Share Capital + mapped Retained Earnings) = cumulative net income since inception`. §6 shows `Retained Earnings (as computed) + Current Year Profit` **is** that cumulative net income since inception, split cleanly at the fiscal-year boundary — so the identity holds exactly, provided every Asset/Liability/Equity account carries a `balance_sheet` mapping row (§4's `Log::warning()` is the operational safety net for that one precondition, the same safety net P&L already relies on for its own Revenue/Expense coverage).

---

## 9. UI Design

Fifth tab in the existing `accountingSectionNav`, alongside Journal Entries, General Ledger, Trial Balance, Profit & Loss. Route: `/accounting/balance-sheet`.

- Same `PageHeader` + `ActionBar` (Refresh) convention as every report page.
- Filter bar: **As Of Date** (single date input, defaults Today, no way to clear), Branch, Company, and Trial Balance's own **Hide zero-balance accounts** toggle (default on) — reused, not reinvented (§8).
- Report body: a sectioned table matching P&L's `renderSection()` pattern — Assets (Current, Non-Current) → Total Assets; Liabilities (Current, Long-Term) → Total Liabilities; Equity (Share Capital, Retained Earnings as two-part-but-single-line, Current Year Profit as a plain derived row with no line items) → Total Equity → Total Liabilities and Equity.
- Footer: reuses **Trial Balance's Balanced/Imbalance pill** (`Card` + colored pill + Total figures), not P&L's plain Net Profit card — Balance Sheet, like Trial Balance, has a genuine pass/fail reconciliation concept (Total Assets vs. Total Liabilities + Equity), which P&L's Net Profit does not.
- Drill-down: clicking an Asset/Liability/Equity account line navigates to the existing, unchanged General Ledger Account Detail page, scoped by `date_to = as_of_date` only (no `date_from`, matching this report's own unbounded-cumulative filter) — same pattern every prior report already established. Clicking the **Current Year Profit** row navigates to the existing, unchanged Profit & Loss report itself, pre-scoped to `date_from = fiscal_year_start` / `date_to = as_of_date` — the natural one-level-up equivalent of "drill down to where this figure came from," since Current Year Profit isn't one account.
- Export/Import: same disabled placeholder convention every report page ships unwired (per Sprint 17B's own adjustment, may also be omitted entirely rather than shown disabled — a per-sprint call, not an architectural one).

---

## 10. Performance

**Aggregation strategy.** `GeneralLedgerService::listAccounts()` is exactly two grouped aggregate queries regardless of account count, called once directly by `BalanceSheetService` and twice more indirectly (once inside each `ProfitLossService::summarize()` call) — so a Balance Sheet page load runs on the order of **six aggregate queries plus two small mapping lookups**, a constant independent of transaction volume, the same O(1)-in-data-size property every report in this module already has. This is the most expensive report built so far, but "more queries, still constant" is not a scaling concern at this stage — flagged honestly, not hidden.

**No pagination needed** — line-item count is bounded by the Chart of Accounts' Asset/Liability/Equity accounts (a handful today), same reasoning Trial Balance already used.

**Index usage.** Fully inherited from General Ledger's existing migration (`journal_entry_lines(chart_of_account_id, journal_entry_id)`, `journal_entries(status, posting_date)`) — no new index, no new query shape.

**If the two `ProfitLossService` sub-calls ever become measurably expensive** (not the case at this data scale): a narrower `GeneralLedgerService` method returning only the net signed total across a date range (skipping the per-account breakdown P&L computes but Balance Sheet doesn't need for Current Year Profit/Retained Earnings) would be the natural next optimization — not built now, since nothing today demonstrates it's needed.

---

## 11. Browser Verification Report

Performed against the running app (`php artisan serve` + `vite dev`, no auth gate encountered) before writing this design, and again after drafting it, per the ticket's mandatory inspection steps:

- **Journal Entries, General Ledger, Trial Balance, Profit & Loss** all confirmed live under the "Accounting Reports" section, four tabs in a shared `SectionNav`, consistent `PageHeader`/`ActionBar`/filter-row-then-table layout across all four — Balance Sheet's proposed fifth tab follows the identical, already-four-times-proven growth pattern; nothing about the module's structure needs to change to accommodate it.
- **Profit & Loss, live, "This Fiscal Year"**: Sales Revenue Rp 200.000, Other Income Rp 15.000, Net Profit Before Tax / Net Profit both Rp 215.000, Tax "Not configured" — matches the exact section/derived-row rendering this design's §3/§9 reuse.
- **Trial Balance, live, This Month, zero-balance toggle off**: confirmed the full 16-account chart, including `1150 Unapplied Customer Payments` typed `Liability` despite its asset-looking code (the concrete proof cited in §0/§4), and confirmed `3000 Owner's Equity` / `3100 Retained Earnings` both render `—` (zero) — grounding §6's Retained Earnings problem in an observed fact, not an assumption.
- **General Ledger, live, unfiltered (all-time)**: Accounts Receivable ending balance Rp 235.000, Tax Payable Rp 20.000, all other Liability/Equity accounts Rp 0. Cross-checked against this design's own balancing identity (§8): Total Assets (`235.000`) = Total Liabilities (`20.000`) + Total Equity, where Total Equity should equal Share Capital (`0`) + Retained Earnings (`0`, prior years — no activity predates this fiscal year, confirmed by This Month vs. This Fiscal Year both showing the same Other Income figure pattern already noted in the Sprint 17B record) + Current Year Profit (`215.000`) = `215.000`. `20.000 + 215.000 = 235.000` — **the identity holds exactly against today's live data**, confirming §5/§6/§8's derivation before any code exists, not just on paper.
- No console errors or failed network requests observed during inspection; no interaction attempted a write (this sprint touches no create/edit/delete surface).

---

## Open Questions

1. **Non-Current Assets / Long-Term Liabilities have no seeded example** — both sections are architecturally ready (§3/§4) but will render empty until a future module (Fixed Assets, long-term loans) adds such an account and its own `ReportAccountMapping` row. Not a gap in this design; nothing to build now.
2. **Period Closing** (flagged by both `PROFIT_LOSS_DESIGN.md` and `TRIAL_BALANCE_DESIGN.md` as a separate future design) is what §6's Retained Earnings computation stands in for. When it's designed, it needs to define: what a closing journal entry looks like, when it fires, and how `BalanceSheetService`'s prior-years `ProfitLossService` call should be bounded so it stops before double-counting years already closed into `3100`. Out of scope here, but §6 explicitly names the seam.
3. **Contra-asset accounts** (e.g. Accumulated Depreciation) — `BalanceSheetSection::isDebitNormal()` (§4) is ready for one the same way `ProfitLossSection` was ready for `4900 Discount Given` before it existed, but none is seeded today, so this is unverified in practice, only by construction.

Stopping here — no code, no migrations, no tests, no frontend pages. Waiting for architectural review.
