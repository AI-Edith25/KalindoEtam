# Profit & Loss (Income Statement) — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests, no frontend pages until approved.

**Revision (post-review)**: the first draft of this document proposed a `pl_section` column on `chart_of_accounts`. That was rejected on review — Chart of Accounts must remain the accounting master, not become coupled to any one report's vocabulary. §4 below now describes a standalone `ReportAccountMapping` layer instead; every other section was already approved and is unchanged.

Frozen inputs this design must not modify: **Accounting Engine, Accounts Receivable, Receipt Entry, Payment Allocation, Credit Note, Debit Note, General Ledger, Trial Balance** — every integration point below calls existing service methods unchanged and adds nothing to `journal_entries`/`journal_entry_lines`/`chart_of_accounts`.

## 0. What already exists (grounding)

Confirmed by inspecting the running app (Chrome — Journal Entries, General Ledger, Trial Balance, all now under the renamed "Accounting Reports" sidebar section) and reading `GeneralLedgerService`/`TrialBalanceService`/`ChartOfAccount`/`AccountType`/`ChartOfAccountsSeeder` before writing anything below:

- **`GeneralLedgerService::listAccounts(array $filters): array`, unchanged, already computes everything a Profit & Loss needs as raw input** — confirmed live: 16 accounts, `{account, opening_balance, debit, credit, ending_balance}` per row, exactly the same shape Trial Balance already builds on. P&L does not reimplement this; it calls the same one method.
- **`ChartOfAccount::isDebitNormal()` (existing, unchanged)** is still the only sign-convention logic anywhere in this feature area — already reused three times (Credit Note/Debit Note's journal routing → General Ledger's `signedMovement()` → Trial Balance's `placeBalance()`), and P&L reuses it a fourth time, the same way, not a new way.
- **`AccountType` (existing, unchanged) has exactly five values**: `asset`, `liability`, `equity`, `revenue`, `expense` — confirmed in `app/Enums/AccountType.php`. This is coarser than a Profit & Loss needs (§4 below), which is the one real gap this design has to solve.
- **`ChartOfAccountsSeeder` (existing, unchanged)** already seeds a 16-account chart whose `code` ranges follow a de facto convention: `1xxx` asset, `2xxx` liability, `3xxx` equity, `4xxx` revenue (`4000` Sales Revenue, `4050` Sales Returns and Allowances, `4100` Other Income), and expense accounts spanning `4900` (Discount Given), `5000`/`5100` (Cost of Goods Sold, Purchase Expense), `6000` (Operating Expenses). This convention is real (every seeded account already follows it) but **not enforced or declared anywhere in the schema** — it lives only in the seeder's comments and the order accounts happen to have been added in. §4 explains why this matters.
- **No period-closing exists** — confirmed already in `TRIAL_BALANCE_DESIGN.md` §0/Open Question 2, still true. Revenue and Expense accounts are never zeroed at fiscal year-end; their `journal_entry_lines` simply keep accumulating forever, the same as every other account. §3 explains why a Profit & Loss report is unaffected by this (it doesn't need closing entries the way a Balance Sheet-style cumulative report would).
- **`Company.fiscal_year_start` (existing, unchanged)** is already reused by Trial Balance's "This Fiscal Year" preset (frontend-only date resolution, no backend parameter) — P&L reuses the identical preset, not a second implementation.
- The **Accounting Reports** section (renamed from "Accounting" in the prior UX pass) already has three tabs — Journal Entries, General Ledger, Trial Balance — confirmed live via `accountingSectionNav`. This design adds Profit & Loss as a fourth tab, the same growth pattern the section has followed twice already (General Ledger in Sprint 15B, Trial Balance in Sprint 16A).

---

## 1. Business Flow

Profit & Loss, like General Ledger and Trial Balance, is a **read model** — no `Documentable` lifecycle, nothing a user creates, edits, or deletes. It answers one question: *"for a chosen reporting period, how much did the business earn, laid out the way an Income Statement expects to be read?"*

The entire computation is delegated, one level deeper than Trial Balance:

```
ProfitLossService::summarize(filters)
  └─ calls GeneralLedgerService::listAccounts(filters)   ← the ONLY balance computation in this design
       └─ (unchanged) calls GeneralLedgerRepository's aggregate queries over journal_entries/journal_entry_lines
  └─ calls ReportAccountMappingRepository::forStatementType(PROFIT_LOSS)  ← one new, small lookup query (§4)
  └─ discards opening_balance/ending_balance (§3 — a P&L wants period movement, not a cumulative balance)
  └─ computes each account's signed period movement via ChartOfAccount::isDebitNormal() (§3)
  └─ classifies each Revenue/Expense account into one P&L section, via its ReportAccountMapping row (§4)
  └─ sums each section into a subtotal, derives Gross Profit / Operating Income / Net Profit (§5)
```

Nothing below this recomputes a balance from `journal_entries`/`journal_entry_lines` directly. `ProfitLossService` is a thin presentation layer over `GeneralLedgerService`, exactly the relationship `TrialBalanceService` already has to it — a second report built on the same one read model, not a second read model.

---

## 2. Service Reuse Strategy

| Existing service/method | Reused for | Reused unchanged? |
|---|---|---|
| `GeneralLedgerService::listAccounts(array $filters)` | The one and only balance/movement computation — every account's `debit`/`credit` for the filtered period | Yes, zero changes |
| `GeneralLedgerRepository`'s aggregate queries | Indirectly, through `listAccounts()` | Yes, zero changes |
| `ChartOfAccount::isDebitNormal()` | Sign convention — same primitive General Ledger and Trial Balance already use | Yes, zero changes |
| `ChartOfAccountRepository::allOrderedByCode()` | Indirectly, through `listAccounts()` | Yes, zero changes |
| Trial Balance's Reporting Period preset resolution (`resolvePeriodPreset`, frontend) | Date Range / This Month / This Quarter / This Fiscal Year presets | Yes, identical UI behavior, no new backend concept |
| `Company.fiscal_year_start` | "This Fiscal Year" preset's boundary | Yes, zero changes |

**New, report-specific logic** (the only things this design actually adds):

1. **`ProfitLossService`** itself — a new class, constructor-injecting `GeneralLedgerService` and the new `ReportAccountMappingRepository` (§4), the same shape `TrialBalanceService` already has, plus one small lookup dependency.
2. **Section classification** — deciding which P&L section (Revenue, Cost of Goods Sold, Operating Expenses, Other Income, Other Expense) each Revenue/Expense account belongs to. This is new because nothing existing answers it today (§4) — `AccountType` alone only distinguishes Revenue from Expense, not which *kind* of Revenue or Expense — and is answered by a new, standalone mapping layer (§4), not by `chart_of_accounts` itself.
3. **Period-movement-only balance logic** — P&L uses each account's in-range `debit`/`credit` alone (via `isDebitNormal()`), explicitly ignoring `listAccounts()`'s `opening_balance`/`ending_balance` fields. This is the one place P&L's math genuinely differs from Trial Balance's (§3), even though both call the identical upstream method.
4. **Subtotal derivation** — Gross Profit, Operating Income, Net Profit Before Tax, Net Profit — pure arithmetic over the section sums, no new query.

One new, small repository — `ReportAccountMappingRepository` (§4), reading the new `report_account_mappings` table only. `ProfitLossService` still never queries `journal_entries`/`journal_entry_lines`/`chart_of_accounts` directly — the exact same discipline `TrialBalanceService` already follows; the mapping lookup is the one addition to that rule, and it reads a table dedicated to report classification, not the accounting master itself.

---

## 3. Balance Calculation — period movement, not cumulative balance

### Why P&L cannot reuse Trial Balance's `ending_balance` the way Trial Balance reuses General Ledger's

Trial Balance (and General Ledger) show a **cumulative, as-of-date** balance — `opening_balance + period movement`. That's correct for Asset/Liability/Equity accounts (a Balance Sheet is inherently cumulative — "how much cash exists right now"), and it's *also* what Trial Balance currently shows for Revenue/Expense accounts, because Trial Balance's job is to prove the whole chart still balances, not to isolate one period's income.

A Profit & Loss is different: it must show **only the current period's activity** for Revenue and Expense accounts — "how much was earned *this month*," not "how much has ever been earned since the account was created." Because this codebase has no period-closing (§0), a Revenue/Expense account's `opening_balance` (the sum of everything before `date_from`) is **every prior period's income all mixed together**, not zero. If `ProfitLossService` reused `ending_balance` the way Trial Balance does, a January P&L run in July would show January's income *plus* every month since — wrong.

The fix requires no schema change and no new query: **P&L uses only the `debit`/`credit` fields `listAccounts()` already returns for the filtered date range**, and discards `opening_balance`/`ending_balance` entirely for the accounts it reports on:

```php
protected function periodMovement(ChartOfAccount $account, float $debit, float $credit): float
{
    // Same formula as GeneralLedgerService::signedMovement() and TrialBalanceService's
    // placeBalance() — reused a fourth time, not reinvented. The only difference from
    // Trial Balance is that P&L never adds opening_balance to this figure (see above).
    return $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
}
```

This is why `date_from` is **mandatory** for Profit & Loss (§6) in a way it isn't for General Ledger/Trial Balance — an unbounded range would report "all income since inception" under the label "this period," which is meaningless for an Income Statement.

### Sign convention, presentation

Revenue and Other Income accounts are credit-normal — a positive `periodMovement` is the normal, expected case (money earned) and displays as a positive figure. Expense accounts (including Cost of Goods Sold and Operating Expenses) are debit-normal — a positive `periodMovement` is also the normal case (money spent) but is **displayed as a positive figure that gets subtracted**, not as a negative number on screen (matching every Income Statement convention: "Less: Cost of Goods Sold `Rp 50,000`", not "`-Rp 50,000`"). The subtraction happens in the subtotal arithmetic (§5), not by flipping the account's own sign — an account with an abnormal balance (e.g., a contra-expense credit memo posted against Operating Expenses) still displays its true signed movement, exactly like Trial Balance's abnormal-balance handling, rather than being clamped.

---

## 4. Chart of Accounts Mapping

### Is `account_type` sufficient? No — and here's the concrete proof from this exact chart

| Code | Name | `account_type` | Where it must appear on a real Income Statement |
|---|---|---|---|
| `4000` | Sales Revenue | `revenue` | **Revenue** (top line) |
| `4050` | Sales Returns and Allowances | `revenue` | **Revenue** (contra, netted against `4000`) |
| `4100` | Other Income | `revenue` | **Other Income** (below Operating Income — *not* top-line Revenue) |
| `4900` | Discount Given | `expense` | **Revenue** section, as a deduction (a sales discount, not an operating cost — its own `4xxx` code already signals this, despite being typed `expense` for debit/credit sign purposes) |
| `5000` | Cost of Goods Sold | `expense` | **Cost of Goods Sold** |
| `5100` | Purchase Expense | `expense` | **Cost of Goods Sold** (adjacent `51xx` code, same section as `5000`) |
| `6000` | Operating Expenses | `expense` | **Operating Expenses** |

Two accounts share `revenue` (`4000`, `4100`) but belong in different sections; four accounts share `expense` (`4900`, `5000`, `5100`, `6000`) but span three different sections. **`account_type` genuinely cannot answer "which P&L section" — it was only ever designed to answer "which side of the ledger does this account increase on," and it still does that job correctly; it was never meant to carry report-grouping information.**

### What already exists that *could* answer it, and why it's not clean enough to build on directly

The `code` ranges above are consistent and *look* like a ready-made answer (`4xxx` revenue-ish, `5xxx` cost-of-sales, `6xxx` operating) — but relying on hardcoded numeric ranges in `ProfitLossService` would mean:
- The boundary between "Revenue" and "Other Income" (`4000`–`4099` vs `4100`–`4899`) is a convention that exists only in the seeder's comments, never validated or enforced when a new account is created via `POST chart-of-accounts`.
- `4900` (Discount Given) already breaks a naive "first digit of code" rule — it's a `4xxx` code but an `expense` type, and it needs a specific carve-out (`4900`–`4999`) that a future account added at, say, `4200` would silently misclassify as "Other Income" instead of "Revenue" if someone reused that range for something else.
- Any new module (Purchase, Payroll) adding an expense account later has no schema-level guidance on which `5xxx`/`6xxx`/`7xxx` sub-range to use — it's tribal knowledge, not a constraint.
- It wouldn't generalize past Profit & Loss anyway: Balance Sheet, Cash Flow, an IFRS variant, and a Tax report each need their own grouping of the same accounts, and a code-range parser hardcoded for one report's vocabulary can't answer a different report's question about the same account.

### Why this must NOT live on `chart_of_accounts` (revised per review)

The first draft of this section recommended a single new nullable `pl_section` column directly on `chart_of_accounts`. On review, that was rejected: Chart of Accounts is the **accounting master** — the record every posting module (Invoice, Credit Note, Debit Note, Payment Allocation, manual Journal Entries) reads to know an account's code, name, type, and active status. It must stay reusable by every future report without being reshaped around any one of them. A `pl_section` column answers only "where does this account go on a Profit & Loss" — the moment a Balance Sheet or Cash Flow design needs a *different* grouping of the same accounts, `chart_of_accounts` would need a second column, then a third, coupling the master record tighter to each report added rather than looser.

### Recommended approach: a standalone `ReportAccountMapping` layer

Classification moves into its own table, entirely separate from `chart_of_accounts`, the same way `GeneralLedgerService`/`TrialBalanceService`/`ProfitLossService` already live one layer above the accounting master rather than inside it:

```php
Schema::create('report_account_mappings', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('chart_of_account_id')->constrained('chart_of_accounts');
    $table->string('statement_type'); // ReportStatementType enum value — see below
    $table->string('section');        // vocabulary is scoped to statement_type — see below
    $table->unsignedInteger('display_order')->nullable(); // optional override; null falls back to account code order
    $table->timestamps();
    $table->softDeletes();

    $table->unique(['chart_of_account_id', 'statement_type']);
});
```

```php
enum ReportStatementType: string
{
    case PROFIT_LOSS = 'profit_loss';
    // BALANCE_SHEET, CASH_FLOW, TAX_REPORT, PROFIT_LOSS_IFRS, etc. — each added as its own report is designed,
    // a new enum case plus new mapping rows, never a new chart_of_accounts column and never a new table.
}
```

- **One row per `(chart_of_account_id, statement_type)` pair**, not one row per account. The same account can carry a *different* `section` under `profit_loss` than it will under a future `profit_loss_ifrs` or `tax_report` statement type, because each is a separate row — the same Chart of Accounts serves multiple report taxonomies simultaneously, with no conditional logic on the account itself.
- `section`'s vocabulary is scoped to its `statement_type`: for `profit_loss`, the same five buckets the proof table above already established — `revenue` / `other_income` / `cost_of_goods_sold` / `operating_expense` / `other_expense` — just relocated off `chart_of_accounts` and onto this row. Validated per statement type at the request layer (`Rule::in([...])`, the same pattern `IndexGeneralLedgerRequest` already uses for `status`), not a rigid cross-report enum and not a separate "allowed sections" catalog table (see rejected alternatives below).
- An account with **no** `report_account_mappings` row for a given `statement_type` simply never appears on that report — every Asset/Liability/Equity account, by construction, never gets a `profit_loss` row at all, the same "the report only shows what belongs on it" principle §5 already relies on.
- `chart_of_accounts` gains **zero new columns**. `AccountType`, `isDebitNormal()`, and every existing query (`allOrderedByCode()`, `listAccounts()`, General Ledger, Trial Balance, the Chart of Accounts page itself) stay completely untouched — none of them know this table exists, and it stores classification metadata only (which section, for which report), never a calculated balance, so it doesn't reintroduce the "no summary tables, no persisted balances" concern this whole design otherwise avoids.

**`ReportAccountMappingRepository`** — the one new, small repository this design needs:

```php
class ReportAccountMappingRepository
{
    /** @return array<string, ReportAccountMapping> keyed by chart_of_account_id */
    public function forStatementType(ReportStatementType $type): array
    {
        return ReportAccountMapping::query()
            ->where('statement_type', $type->value)
            ->get()
            ->keyBy('chart_of_account_id')
            ->all();
    }
}
```

One query, called once per `ProfitLossService::summarize()` call, joined in PHP against `listAccounts()`'s already-fetched rows (§9) — no N+1, no per-account query.

### Why this generalizes to Balance Sheet / Cash Flow / IFRS / Tax without new columns

- **Balance Sheet** — `statement_type = 'balance_sheet'`, sections like `current_asset` / `fixed_asset` / `current_liability` / `long_term_liability` / `equity`: new enum case, new mapping rows.
- **Cash Flow** — `statement_type = 'cash_flow'`, sections like `operating` / `investing` / `financing`: same pattern.
- **IFRS variants** — `statement_type = 'profit_loss_ifrs'` (or however that future design names it): a parallel set of mapping rows for the same accounts, coexisting with the plain `profit_loss` rows.
- **Tax reports** — `statement_type = 'tax_report'`, sections reflecting whatever a tax authority's own categories are: again, new rows, not new schema.

Every case above adds a `ReportStatementType` case and a batch of `report_account_mappings` rows — never a migration touching `chart_of_accounts`.

### Alternatives considered and rejected

- **`pl_section` column on `chart_of_accounts`** (this design's original recommendation) — rejected per review: couples the accounting master to one report's vocabulary, and doesn't generalize — a second report needing a different classification of the same account would need a second column, then a third.
- **Deriving sections from `code` ranges at read time** — already rejected above for being undocumented and fragile; doubly so here since it wouldn't generalize past Profit & Loss at all.
- **A single polymorphic "taxonomy catalog" table** listing every valid `(statement_type, section)` pair — considered, rejected as premature. This project has one report type today and a short, known list of future ones; a metadata-driven catalog solves a scaling problem (dozens of dynamically-defined report types) this codebase doesn't have. Every other classification concept here (`AccountType`, `DocumentStatus`, `StockTransactionType`, ...) is a plain PHP enum, not a DB-driven catalog — `ReportStatementType` follows that same established convention, and each statement type's own small set of valid sections is validated in the request layer, not modeled as data.

This satisfies the review's requirement directly: a new report (Balance Sheet, Cash Flow, an IFRS variant, a Tax report) is built by adding an enum case and mapping rows, never by touching `chart_of_accounts`.

---

## 5. Report Structure

Every section below is a sum of `periodMovement()` (§3) across the accounts assigned to it (§4), computed from a single `GeneralLedgerService::listAccounts($filters)` call — no per-section query.

```
Revenue                                          sum(mapping.section = 'revenue', statement_type = profit_loss)
  Less: Sales Returns, Allowances & Discounts     (4050, 4900 — already included in the Revenue sum above,
                                                    itemized as sub-lines the same way Trial Balance
                                                    itemizes accounts under one column, not a second sum)
Net Revenue                                       = Revenue section subtotal

Less: Cost of Goods Sold                          sum(mapping.section = 'cost_of_goods_sold')

Gross Profit                                      = Net Revenue − Cost of Goods Sold

Less: Operating Expenses                          sum(mapping.section = 'operating_expense')

Operating Income                                  = Gross Profit − Operating Expenses

Other Income                                      sum(mapping.section = 'other_income')
Less: Other Expenses                              sum(mapping.section = 'other_expense')  (none seeded yet —
                                                    section renders "No other expenses this period" if empty, §7)

Net Profit Before Tax                             = Operating Income + Other Income − Other Expenses

Tax                                                out of scope this sprint — no Tax Expense account or
                                                    tax-calculation concept exists anywhere in this codebase
                                                    today (confirmed: 2100 Tax Payable is a Liability the
                                                    Accounting Engine already posts to for sales tax collected,
                                                    not an income-tax expense line). Rendered as a fixed
                                                    "Rp 0" row with a footnote, not computed — flagged as
                                                    Open Question 1, not silently assumed.

Net Profit                                         = Net Profit Before Tax − Tax
```

Every row that's a **subtotal or derived figure** (Net Revenue, Gross Profit, Operating Income, Net Profit Before Tax, Net Profit) is pure arithmetic over the section sums above it — never its own query, never a separate `listAccounts()` call.

### How accounts are grouped into each section (restated plainly)

Each Revenue/Expense account's `ReportAccountMapping` row for `statement_type = profit_loss` (§4) places it into exactly one of the five sums above; `periodMovement()` (§3) gives it a signed figure; the section sum is a plain addition. An account with no such mapping row (every Asset/Liability/Equity account, by construction) never appears on this report — the same "the report only shows what belongs on it" principle Trial Balance already applies by only ever showing accounts that exist, not a filtered subset of a bigger structural concept.

---

## 6. Filtering

| Filter | Status | Mandatory? |
|---|---|---|
| **Reporting Period** (preset dropdown) | Reused as-is from Trial Balance (This Month/This Quarter/This Fiscal Year/Custom Range → `date_from`/`date_to`) | **Yes — `date_from` is mandatory**, unlike General Ledger/Trial Balance where it's optional. §3 explains why: an unbounded range would sum *all* income since inception under the "this period" label. The UI defaults to "This Month" (never "All Time") and offers no way to clear it, the one behavioral difference from Trial Balance's own filter bar. |
| **Date Range** (Custom Range) | Reused as-is | `date_to` optional, defaults to today — same as General Ledger/Trial Balance |
| **Branch** | Reused as-is (General Ledger's `branch_id`), same dormant status already documented (§0 of both prior designs — no module populates `journal_entry_lines.branch_id` yet) | No |
| **Company** | Reused as-is (General Ledger's `company_id`), same dormant status | No |

No new filter concept — every backend-relevant filter passes straight through to `GeneralLedgerService::listAccounts($filters)` unchanged, exactly like Trial Balance. Account Range (Trial Balance's own filter) does not carry over — a P&L is grouped by section, not by account code order, so a code-range filter has no natural meaning here and is deliberately omitted rather than included for consistency's sake alone.

---

## 7. Report Behavior

- **Calculation flow**: `ProfitLossService::summarize($filters)` → one call to `listAccounts($filters)` + one call to `ReportAccountMappingRepository::forStatementType(PROFIT_LOSS)` (§4) → group each ledger row by its mapped section, computing `periodMovement()` per row → sum per section → derive subtotals (§5) → return `{sections: [...], gross_profit, operating_income, other_income_total, other_expense_total, net_profit_before_tax, tax, net_profit}`.
- **Ordering**: sections render in the fixed order shown in §5 (Revenue → COGS → Gross Profit → Operating Expenses → Operating Income → Other Income → Other Expenses → Net Profit Before Tax → Tax → Net Profit); accounts within a section render by `code` ascending, the same ordering `allOrderedByCode()` already guarantees everywhere else.
- **Subtotal generation**: every subtotal is derived arithmetic (§5), never re-queried.
- **Zero-balance accounts**: unlike Trial Balance (which defaults to hiding them via a toggle, since a Balance Sheet-style report still lists every account), a P&L account with zero period movement is simply **omitted from its section's line-item list** by default — an Income Statement conventionally never shows "Rent Expense: Rp 0" — with the section subtotal itself still rendering even if every account under it is zero (e.g., "Other Expenses: Rp 0" is still a real, expected line when nothing was posted there this period, exactly like Trial Balance's own "legitimate zero row" principle, just applied at the section level instead of the account level).
- **Sign conventions**: see §3 — Revenue/Other Income display as earned (positive, added); Cost of Goods Sold/Operating Expenses/Other Expenses display as spent (positive, subtracted in the subtotal math, never shown as an on-screen negative number) unless an account carries a genuine abnormal balance, which displays signed exactly like Trial Balance's own abnormal-balance handling (§3 of that design).
- **Periods with no activity**: every section subtotal is `Rp 0`, Gross Profit/Operating Income/Net Profit all resolve to `Rp 0`, and the report renders its normal structure (not an empty-state placeholder) — because unlike Trial Balance's "an account with no activity is a legitimate row," a P&L period with genuinely zero activity is a legitimate *statement* (a new company's first, empty month), not an error state.

---

## 8. UI Design

Placed under `features/accounting/`, a **fourth tab** alongside Journal Entries, General Ledger, and Trial Balance in the existing `accountingSectionNav` — confirmed live (currently three tabs, following the exact growth pattern General Ledger and Trial Balance each already established). Route: `/accounting/profit-loss`.

### Consistency with General Ledger / Trial Balance (confirmed live, this session)

- Same `PageHeader` + `ActionBar` (Refresh / disabled Export / disabled Import) convention.
- Same description-phrasing pattern established in the prior UX pass ("A read-only report of ...").
- Same `SectionNav` + filter-row-then-table layout, same `flex flex-col gap-4` page spacing.
- Same footer-`Card` convention Trial Balance already uses for its Total Debit/Total Credit row — Profit & Loss's equivalent footer is the Net Profit figure (§below), not a Debit/Credit pair (a P&L has no "balanced/imbalanced" concept the way Trial Balance does — there's nothing to reconcile against).

### Profit & Loss page (only page this design adds — no detail/drill-down page of its own, see below)

- Filter row: Reporting Period preset (mandatory, defaults "This Month"), Custom Range (shown when selected), Branch, Company — the same filter primitives Trial Balance's filter bar already uses, minus Account Range and the zero-balance toggle (§6/§7 — neither concept applies to a P&L).
- Report body: a **sectioned table**, not a flat `DataTable` — each section (Revenue, Cost of Goods Sold, Operating Expenses, Other Income, Other Expenses) renders as a labeled group of account line-items (Name, Amount — no Code/Type columns needed here, since a P&L is read by section and account name, not by chart-of-account browsing), followed by that section's subtotal row in bold, matching the visual weight Trial Balance's own Total row already uses.
- Derived rows (Gross Profit, Operating Income, Net Profit Before Tax, Tax, Net Profit) render as full-width emphasized rows between sections, with Net Profit as the final, most visually prominent row — the report's equivalent of Trial Balance's Balanced/Imbalance pill, except always a plain figure (there's no pass/fail state for a P&L).
- No drill-down of its own: clicking an account line-item navigates to the **existing, unchanged** `/accounting/general-ledger/:accountId` page — the exact same pattern Trial Balance already established last sprint — carrying the same resolved `date_from`/`date_to` as query params so the drill-down opens already scoped to the report's period.
- Export button: same disabled placeholder every list/report page in this codebase already ships unwired.

---

## 9. Performance

**Aggregation strategy.** Identical to Trial Balance's own (its §7, still true) — `GeneralLedgerService::listAccounts()` already runs exactly two grouped aggregate queries regardless of account count. `ProfitLossService` adds exactly **one** additional query beyond that — `ReportAccountMappingRepository::forStatementType()` (§4), a single unfiltered-by-account lookup against a small table, joined in PHP against `listAccounts()`'s rows, never one query per account. Section grouping and subtotal arithmetic themselves are pure PHP over the already-fetched arrays, the same "read model built on read model" relationship Trial Balance has to General Ledger.

**Pagination.** Not needed — a P&L's line-item count is bounded by the Chart of Accounts' Revenue/Expense accounts (a handful today, dozens at a larger company), the same reasoning Trial Balance already used to justify skipping pagination.

**Index usage.** Fully inherited from General Ledger's own migration (`journal_entry_lines(chart_of_account_id, journal_entry_id)`, `journal_entries(status, posting_date)`) — no new index, no new query shape (`listAccounts()`'s date-range aggregate is the same query whether the caller is General Ledger, Trial Balance, or Profit & Loss).

**Large datasets.** Same answer as Trial Balance's own §7: the dataset that could grow large (journal entry lines behind each account) is already handled by `listAccounts()`'s aggregate-query approach; `ProfitLossService` never touches line-level data, only the already-summed per-account period movement.

---

## Open Questions

**Resolved**: Chart of Accounts classification mechanism (§4) — originally proposed as a `pl_section` column on `chart_of_accounts`, rejected on review and replaced with the standalone `ReportAccountMapping` layer described in §4. No longer open.

1. **Tax** — no Tax Expense account or tax-calculation concept exists anywhere in this codebase today (`2100 Tax Payable` is a sales-tax-collected Liability the Accounting Engine already posts to, unrelated to income tax). This design renders a fixed `Rp 0` Tax row with a footnote rather than inventing a tax calculation. If a future sprint wants real tax handling, it needs its own design (a Tax Expense account mapped to `operating_expense` or a new section via its own `ReportAccountMapping` row, plus wherever the tax rate/rule itself would live) — out of scope here.
2. **Retained Earnings / period closing** — this design deliberately does not need it (§3 explains why P&L uses period movement, not a cumulative balance), but it remains the same "separate, larger future design" the Trial Balance design already flagged (its Open Question 2) — a Balance Sheet, whenever designed, would need it; Profit & Loss does not.

Stopping here — no code, no migrations, no tests, no frontend pages. Waiting for architectural review.
