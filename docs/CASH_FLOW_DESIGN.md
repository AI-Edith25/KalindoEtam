# Cash Flow Statement — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests, no frontend pages until approved.

Frozen inputs this design must not modify: **Accounting Engine, Accounts Receivable, Receipt Entry, Payment Allocation, Credit Note, Debit Note, General Ledger, Trial Balance, Profit & Loss, Balance Sheet** — every integration point below calls existing service methods unchanged and adds nothing to `journal_entries`/`journal_entry_lines`/`chart_of_accounts`. `report_account_mappings` (existing table, Sprint 17B) gains new rows for a new `statement_type` value only — no migration.

## 0. What already exists (grounding)

Confirmed by inspecting the running app (Chrome — Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet, all under "Accounting Reports") and re-reading `GeneralLedgerService`/`ProfitLossService`/`BalanceSheetService`/`ReportAccountMapping` before writing anything below:

- **`1100 Cash and Bank` currently has `Rp 0` opening, debit, credit, and ending balance — confirmed live** (General Ledger, unfiltered/all-time). No module in this test data has posted to it: Invoice/Delivery only touch Accounts Receivable and Revenue, never Cash and Bank directly (a Receipt Entry would, but none exists in this session's data). This is itself a useful, honest worked example (§9): a company that has earned Rp 215.000 of profit but collected none of it in cash yet is exactly the scenario a Cash Flow Statement's working-capital adjustments exist to explain, and it gives a trivial-but-correct check case — Opening Cash `0` + Net Cash Movement `0` = Closing Cash `0` — for the identity §8 requires.
- **`GeneralLedgerService::listAccounts(array $filters)`, unchanged, already returns BOTH `opening_balance` and `ending_balance` per account in a single call when given `date_from`/`date_to`** — confirmed by re-reading `GeneralLedgerRepository::openingTotalsByAccount()`/`periodTotalsByAccount()`. This is the one fact this design leans on hardest (§1/§9): the *change* in any account's balance over a period — exactly what a Cash Flow Statement's Indirect Method needs for every Balance Sheet account — is `ending_balance − opening_balance` from a single, already-existing call, not a new computation and not two separate calls.
- **`ProfitLossService::summarize(array $filters)`, unchanged, already computes a correctly-netted net profit for any date range** (contra-account handling included, per Sprint 17B). Reused directly as the Operating Activities section's starting figure (§5/§6), never re-derived.
- **`BalanceSheetService`, unchanged, already proved (Sprint 18B, live browser data) that `Total Assets = Total Liabilities + Equity` holds exactly** via the same "double-entry nets to zero across the whole chart" identity this design's own §8 guarantee depends on — Cash Flow's balancing proof (§8) is the same fact, algebraically rearranged around Cash specifically instead of around the whole Balance Sheet.
- **No Non-Current Asset or Long-Term Liability account is seeded** — confirmed again live via Balance Sheet's own empty "Non-Current Assets"/"Long-Term Liabilities" sections (Sprint 18B's own Open Question 1, still true). Investing and part of Financing Activities will render empty today, the same legitimate-empty-section handling already established.
- **The Accounting Reports section now has five tabs** (Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet) in `accountingSectionNav` — confirmed live. This design adds Cash Flow as a sixth tab, the same growth pattern the section has followed five times already.

---

## 1. Architecture Design

Cash Flow, like every other report in Accounting Reports, is a **read model** — no `Documentable` lifecycle, nothing created/edited/deleted. It answers: *"during a chosen period, how and why did the business's cash balance change?"*

```
CashFlowService::summarize(filters)
  └─ calls ProfitLossService::summarize({date_from, date_to, ...})           → Net Profit for the Period (§6)
  └─ calls GeneralLedgerService::listAccounts({date_from, date_to, ...})     → opening_balance AND ending_balance
       └─ (unchanged) the SAME single call every other report already makes  for every account, in ONE call (§9)
  └─ calls ReportAccountMappingRepository::forStatementType(CASH_FLOW)       → one new lookup, same repository (§5)
  └─ splits ledger rows into: Cash & Equivalents (Opening/Closing Cash) / Operating / Investing / Financing (§5)
  └─ for each non-cash mapped account: contribution = isDebitNormal ? -(ending_balance - opening_balance) : +(ending_balance - opening_balance)   (§8)
  └─ sums each activity section, derives Net Increase/Decrease in Cash, Opening Cash, Closing Cash
```

`CashFlowService` sits at the same layer `BalanceSheetService` already occupies — it calls `ProfitLossService` directly (never re-deriving Revenue/Expense arithmetic) and `GeneralLedgerService` directly (never re-deriving balance arithmetic), the same two dependencies `BalanceSheetService` has. No new balance computation exists anywhere in this design.

```
GeneralLedgerService
        ▲
        │
ProfitLossService
        ▲
        │
BalanceSheetService   CashFlowService
        (both depend on GeneralLedgerService + ProfitLossService; neither is depended on by the other)
```

**One-way dependency, maintained**: `CashFlowService` depends on `GeneralLedgerService` and `ProfitLossService`, the same two dependencies `BalanceSheetService` has — it does **not** depend on `BalanceSheetService` (§3 explains why it doesn't need to, even though the ticket allows it "if appropriate"). Neither `ProfitLossService` nor `GeneralLedgerService` nor `BalanceSheetService` gains any knowledge of `CashFlowService`'s existence.

---

## 2. Cash Flow Method Decision

### Direct Method — rejected

The Direct Method lists actual cash receipts and payments by category ("Cash received from customers", "Cash paid to suppliers", "Cash paid for operating expenses"). This requires classifying **every individual line that touches a cash account** by its business purpose — which needs one of:

- A new column on `journal_entry_lines` (e.g. `cash_flow_category`) — **rejected outright**: it would touch the frozen Accounting Engine's own posting logic (every module that creates a journal entry — Invoice, Credit Note, Debit Note, Receipt Entry, Payment Allocation, manual Journal Entry — would need to start populating it), directly violating "do not modify the accounting engine" and the same discipline that rejected a `pl_section` column on `chart_of_accounts` in `PROFIT_LOSS_DESIGN.md §4`.
- Inferring the category from `reference_type` at read time (Invoice → operating receipt, Purchase → operating payment, etc.) — fragile in the same way `PROFIT_LOSS_DESIGN.md §4` already rejected inferring P&L sections from `chart_of_accounts.code` ranges: it's undocumented, unenforced, and every new reference type (a future Fixed Asset Purchase, a future Loan Disbursement) would need a hardcoded classification rule added to `CashFlowService` itself rather than declared as data.

Neither option reuses existing architecture; both require new classification machinery the Direct Method specifically needs and the Indirect Method does not.

### Indirect Method — recommended

The Indirect Method starts from Net Profit and adjusts for non-cash items and changes in Balance Sheet accounts. Every input it needs already exists as a direct method call:

- **Net Profit for the period** — `ProfitLossService::summarize()`, unchanged, already computed exactly this way.
- **Changes in Balance Sheet accounts** (Accounts Receivable, Inventory, Accounts Payable, etc.) — `GeneralLedgerService::listAccounts({date_from, date_to})`'s existing `opening_balance`/`ending_balance` pair, unchanged, already computed exactly this way (§0).
- **Classification of which accounts are "operating" vs "investing" vs "financing"** — the same `ReportAccountMapping` architecture Profit & Loss and Balance Sheet already use (§5), not a new mechanism.

### Recommendation

**Indirect Method.** It requires zero new balance computation and zero new schema — every number it needs is a direct, unmodified call to `ProfitLossService` or `GeneralLedgerService`, the literal architecture requirement this sprint states ("maximizing reuse... avoid duplicate calculations"). The Direct Method would require inventing a transaction-level classification concept this codebase has never had and the ticket explicitly forbids building (no schema changes, no accounting engine changes). This is also the natural continuation of the pattern the last two reports already established: Profit & Loss reused `GeneralLedgerService`; Balance Sheet reused both `GeneralLedgerService` and `ProfitLossService`; Cash Flow reuses the identical two, one layer further.

---

## 3. Service Reuse Strategy

| Existing service/method | Reused for | Reused unchanged? |
|---|---|---|
| `ProfitLossService::summarize({date_from, date_to, ...})` | Net Profit for the Period — Operating Activities' starting figure (§6) | Yes, zero changes |
| `GeneralLedgerService::listAccounts({date_from, date_to, ...})` | Every non-cash Balance Sheet account's period *change* (`ending_balance − opening_balance`) **and** the cash account(s)' own opening/ending balance, from the same single call | Yes, zero changes |
| `ChartOfAccount::isDebitNormal()` | Sign convention for each account's contribution — same primitive every prior report reuses | Yes, zero changes |
| `ReportAccountMappingRepository::forStatementType(ReportStatementType $type)` | Section classification lookup — already generic over `$type`, needs no change to accept `CASH_FLOW` | Yes, zero changes |

**Why `BalanceSheetService` itself is not called, even though the ticket allows it "if appropriate":** `BalanceSheetService::summarize()` computes a point-in-time snapshot (one `as_of_date`, unbounded `date_from`) plus two extra `ProfitLossService` sub-calls for Current Year Profit/Retained Earnings — none of which Cash Flow needs. Cash Flow needs a *period's* opening-vs-ending change for the raw Balance Sheet accounts, which is exactly `GeneralLedgerService::listAccounts({date_from, date_to})`'s own `opening_balance`/`ending_balance` pair — calling it directly is strictly cheaper (one call instead of `BalanceSheetService`'s six-query fan-out, §9) and avoids depending on a service whose own output shape (a snapshot, not a range) doesn't match what's needed. This keeps the dependency graph the ticket specifies exactly: `GeneralLedgerService → ProfitLossService → CashFlowService`, with `BalanceSheetService` as a sibling, not a dependency.

**New, report-specific logic** (the only things this design adds):

1. **`CashFlowService`** itself — constructor-injects `GeneralLedgerService`, `ReportAccountMappingRepository`, `ProfitLossService`. Same shape `BalanceSheetService` already has.
2. **`CashFlowSection` enum** — four cases (§5): `CASH_AND_EQUIVALENTS`, `OPERATING_ADJUSTMENT`, `INVESTING_ACTIVITY`, `FINANCING_ACTIVITY`.
3. **`ReportStatementType::CASH_FLOW`** — one new enum case, same table.
4. **Contribution sign rule** — `contribution = isDebitNormal ? -(change) : +(change)`, the mirror image of every prior contribution formula (§8): an asset's *increase* is a cash *use* (negative), a liability/equity's *increase* is a cash *source* (positive). Same `isDebitNormal()` primitive, applied with the opposite polarity because Cash Flow explains what happened *to cash* rather than what an account's own balance is.

No new repository, no new migration, no new balance-computation logic.

---

## 4. Report Structure

```
Operating Activities
  Net Profit for the Period                         ProfitLossService::summarize({date_from, date_to})['net_profit']
  Adjustments for changes in working capital:
    (Increase) / Decrease in Accounts Receivable     -(ending_balance - opening_balance), account 1200
    (Increase) / Decrease in Inventory               -(ending_balance - opening_balance), account 1300
    Increase / (Decrease) in Accounts Payable        +(ending_balance - opening_balance), account 2000
    Increase / (Decrease) in Tax Payable             +(ending_balance - opening_balance), account 2100
    Increase / (Decrease) in Accrued Expenses        +(ending_balance - opening_balance), account 2200
    Increase / (Decrease) in Unapplied Customer Pmts +(ending_balance - opening_balance), account 1150
Net Cash from Operating Activities                   = Net Profit + sum(operating_adjustment contributions)

Investing Activities
  (one line per mapped non-current-asset account — none seeded today, §0)
Net Cash from Investing Activities                   = sum(investing_activity contributions)   (Rp 0 today)

Financing Activities
  Increase / (Decrease) in Owner's Equity             +(ending_balance - opening_balance), account 3000
  (one line per mapped long-term-liability account — none seeded today, §0)
Net Cash from Financing Activities                    = sum(financing_activity contributions)

Net Increase (Decrease) in Cash                       = Operating + Investing + Financing
Opening Cash Balance                                  = sum(opening_balance, section = cash_and_equivalents)
Closing Cash Balance                                  = Opening Cash Balance + Net Increase (Decrease) in Cash
                                                          (cross-checked against sum(ending_balance, cash_and_equivalents) directly — §8)
```

Every subtotal and grand total is pure arithmetic over the single `listAccounts()` call's already-fetched rows plus the single `ProfitLossService` call's `net_profit` — never a second query, the same discipline every prior report in this module follows.

---

## 5. ReportAccountMapping Strategy

**Decision: a new `ReportStatementType::CASH_FLOW` with its own section vocabulary — reusing the mapping *architecture* (table, repository method, model), not Balance Sheet's own `section` values directly.**

This needs justifying, since maximal reuse might suggest reusing Balance Sheet's existing `current_asset`/`current_liability`/etc. mapping rows outright. The reason that doesn't work: Balance Sheet's `current_asset` section deliberately groups Cash and Bank together with Accounts Receivable and Inventory — correct for a Balance Sheet (all three are current assets), but **wrong for Cash Flow**, which must treat Cash and Bank as the thing being explained (Opening/Closing Cash) while treating Accounts Receivable/Inventory as *adjustments* to Operating Activities. No existing vocabulary makes this distinction, so Cash Flow needs its own, the same way Balance Sheet needed its own distinct from Profit & Loss's (`PROFIT_LOSS_DESIGN.md §4` / `BALANCE_SHEET_DESIGN.md §4`).

```php
enum CashFlowSection: string
{
    case CASH_AND_EQUIVALENTS = 'cash_and_equivalents';
    case OPERATING_ADJUSTMENT = 'operating_adjustment';
    case INVESTING_ACTIVITY = 'investing_activity';
    case FINANCING_ACTIVITY = 'financing_activity';

    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::CASH_AND_EQUIVALENTS, self::OPERATING_ADJUSTMENT => true,
            // Operating Adjustment holds AR/Inventory/AP/Tax/Accrued together — a mix of debit-
            // and credit-normal accounts. Unlike ProfitLossSection/BalanceSheetSection, this
            // section's own isDebitNormal() is not used for contribution sign (§8 uses each
            // account's own isDebitNormal() directly, with a flipped polarity) — kept here only
            // for interface consistency with the two prior report sections, unused in practice
            // until a genuine contra account within Operating Adjustment appears.
            self::INVESTING_ACTIVITY => true,
            self::FINANCING_ACTIVITY => false,
        };
    }
}
```

```php
enum ReportStatementType: string
{
    case PROFIT_LOSS = 'profit_loss';
    case BALANCE_SHEET = 'balance_sheet';
    case CASH_FLOW = 'cash_flow';
}
```

`ReportAccountMappingRepository::forStatementType()` needs no change — already generic over `ReportStatementType` (established in both prior designs). A new `CashFlowMappingSeeder` (same declarative code→section shape as `BalanceSheetMappingSeeder`) seeds:

| Code | Name | Cash Flow section |
|---|---|---|
| `1100` | Cash and Bank | `cash_and_equivalents` |
| `1200` | Accounts Receivable | `operating_adjustment` |
| `1300` | Inventory | `operating_adjustment` |
| `1150` | Unapplied Customer Payments | `operating_adjustment` |
| `2000` | Accounts Payable | `operating_adjustment` |
| `2100` | Tax Payable | `operating_adjustment` |
| `2200` | Accrued Expenses | `operating_adjustment` |
| `3000` | Owner's Equity | `financing_activity` |

`3100 Retained Earnings` is **deliberately given no `cash_flow` mapping row** — it is a derived Balance Sheet figure, not a real posted balance (`BALANCE_SHEET_DESIGN.md §6`: nothing has ever posted to it, and it always reads `0` pre-Period-Closing). Its own `change` is `0` by construction, so omitting it changes no total, but omitting it explicitly (rather than mapping it to `financing_activity` and relying on it being zero) avoids double-counting the same money twice once Period Closing exists and starts posting to it — the same forward-looking seam `BALANCE_SHEET_DESIGN.md §6` already flagged.

**No new `chart_of_accounts` column, no schema change.** An account with no `cash_flow` mapping row simply never appears on this report — same "the report only shows what belongs on it" principle, with the same `Log::warning()` visibility net every prior report already provides for its own account types.

---

## 6. Current Year Profit Reuse

Using the Indirect Method (§2), Operating Activities' starting figure is **Net Profit for the Period**, obtained by a single, direct call:

```php
$netProfit = $this->profitLossService->summarize([
    'date_from' => $filters['date_from'],
    'date_to' => $filters['date_to'],
    'branch_id' => $filters['branch_id'] ?? null,
    'company_id' => $filters['company_id'] ?? null,
])['net_profit'];
```

This is deliberately simpler than Balance Sheet's own "Current Year Profit" (`BALANCE_SHEET_DESIGN.md §5`): Balance Sheet needed to internally resolve a fiscal-year boundary because its own filter is a single `as_of_date` snapshot, not a range. Cash Flow's filter **is already a Date Range** (§7) — `date_from`/`date_to` are the report's own primary filter, not something Cash Flow has to derive internally. So there is no fiscal-year anchoring logic to port server-side here at all; the exact range the user picked is passed straight through to `ProfitLossService::summarize()` unchanged. **Zero duplication**: if P&L's net-profit formula changes, Cash Flow's Net Profit changes with it automatically, the same guarantee Balance Sheet already has.

(The Direct Method, had it been chosen, would not need this at all — it never starts from an income-statement figure; it lists actual cash movements directly. This is the one clear simplicity the Direct Method would have offered, and it is the only point in this design where the rejected method would have been simpler — outweighed by everything §2 already established against it.)

---

## 7. Filtering

| Filter | Status | Mandatory? |
|---|---|---|
| **Reporting Period** (preset dropdown) | Reused as-is from Profit & Loss (This Month/This Quarter/This Fiscal Year/Custom Range → `date_from`/`date_to`) | **Yes — `date_from` is mandatory**, same reasoning as Profit & Loss: a Cash Flow Statement explains movement *during* a period; an unbounded range would make Opening Cash `0` (there is no "before" to an unbounded range) and Net Profit "since inception" rather than for a period — meaningless for this report. |
| **Date Range** (Custom Range) | Reused as-is | `date_to` optional, defaults to today — same as every other report |
| **Branch** | Reused as-is (General Ledger's `branch_id`), same dormant status already documented in every prior design | No |
| **Company** | Reused as-is (General Ledger's `company_id`), same dormant status | No |

No new filter concept. Contrast with Balance Sheet's single **As Of Date** (`BALANCE_SHEET_DESIGN.md §7`): Balance Sheet is a snapshot, so it needs one point in time; Cash Flow, like Profit & Loss, explains a *period*, so it needs a range — the same distinction that already separates these two report families in this module.

---

## 8. Report Behavior

- **Calculation flow**: `CashFlowService::summarize($filters)` → one `ProfitLossService::summarize()` call (Net Profit, §6) + one `GeneralLedgerService::listAccounts({date_from, date_to, ...})` call (every account's opening/ending balance, §0) + one `ReportAccountMappingRepository::forStatementType(CASH_FLOW)` call (§5) → classify each mapped account, compute its period `change` (`ending_balance − opening_balance`), sign it via `isDebitNormal() ? -change : +change` → sum per activity section → derive Net Increase/Decrease, Opening Cash, Closing Cash.
- **Ordering**: sections render in the fixed order shown in §4 (Operating → Investing → Financing → Net Increase/Decrease → Opening Cash → Closing Cash); accounts within a section render by `code` ascending, same as every other report.
- **Subtotals**: all derived arithmetic, never re-queried.
- **Opening Cash**: `sum(opening_balance, section = cash_and_equivalents)` — read directly off the same `listAccounts()` call, not a second lookup.
- **Closing Cash**: computed two ways and cross-checked — (a) `Opening Cash + Net Increase (Decrease) in Cash` (the report's headline figure) and (b) `sum(ending_balance, section = cash_and_equivalents)` directly from the same `listAccounts()` row. §8's guarantee below is exactly the proof that (a) always equals (b).
- **Zero-value handling**: an account with zero change over the period is omitted from its section's line-item list (same convention Profit & Loss uses for zero-movement accounts, since Cash Flow — like Profit & Loss — is a period-movement report, not a complete-chart cumulative one like Trial Balance/Balance Sheet); the section subtotal still renders even if every line is zero (e.g. "Net Cash from Investing Activities: Rp 0" is a legitimate line when nothing is seeded there, §0).
- **Sign conventions**: contribution = `isDebitNormal() ? -(change) : +(change)` (§3/§5) — an asset's increase displays as a **negative** adjustment ("Increase in Accounts Receivable" shown as a use of cash), a liability/equity's increase displays as a **positive** adjustment, matching every real Indirect Method statement's convention.
- **Guaranteeing Opening Cash + Net Cash Movement = Closing Cash**: by the same double-entry identity Balance Sheet's own guarantee rests on (`BALANCE_SHEET_DESIGN.md §8`, confirmed live in Sprint 18B: `Assets = Liabilities + Equity` holds at any as-of date), the fundamental accounting equation holds at both `date_from − 1 day` and `date_to`; subtracting the two snapshots gives, for the period alone:

  `Δ(all Assets) = Δ(all Liabilities) + Δ(all Equity) + Net Profit for the Period`

  Splitting `Δ(all Assets)` into `Δ(Cash) + Δ(all other Assets)` and rearranging:

  `Δ(Cash) = Net Profit + [-Δ(other current assets) + Δ(current liabilities)] + [-Δ(non-current assets)] + [Δ(long-term liabilities) + Δ(share capital)]`

  `= Net Profit + Operating Adjustment + Investing Activity + Financing Activity = Net Cash Movement`

  And `Δ(Cash) = Closing Cash − Opening Cash` by definition — so `Opening Cash + Net Cash Movement = Closing Cash` holds exactly, **provided** every non-cash Asset/Liability/Equity account carries a `cash_flow` mapping row (§5's `Log::warning()` is the same operational safety net every prior report already relies on for this one precondition) and Retained Earnings is excluded from the mapping (§5, to avoid double-counting the Net Profit figure already included directly).

---

## 9. UI Design

Sixth tab in the existing `accountingSectionNav`, alongside Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet. Route: `/accounting/cash-flow`.

- Same `PageHeader` + `ActionBar` (Refresh) convention as every report page.
- Filter bar: **Reporting Period** preset (mandatory, defaults "This Month", same component Profit & Loss already uses), Custom Range (shown when selected), Branch, Company — reused as-is, not reinvented.
- Report body: a sectioned table matching Profit & Loss's `renderSection()` pattern exactly — Operating Activities (Net Profit row + working-capital adjustment lines) → Net Cash from Operating Activities; Investing Activities → Net Cash from Investing Activities; Financing Activities → Net Cash from Financing Activities; then three full-width derived rows — Net Increase (Decrease) in Cash, Opening Cash Balance, Closing Cash Balance — with Closing Cash Balance as the final, most visually prominent row, the same "last row is the headline figure" pattern Profit & Loss's Net Profit and Balance Sheet's Total Liabilities and Equity both already establish.
- Footer: reuses **Trial Balance/Balance Sheet's Balanced pill** (not Profit & Loss's plain figure card) — Cash Flow, like Balance Sheet, has a genuine reconciliation concept (Opening + Movement vs. actual Closing), which Profit & Loss's Net Profit does not.
- Drill-down: clicking a working-capital adjustment line (Accounts Receivable, Accounts Payable, etc.) navigates to the existing, unchanged General Ledger Account Detail page, scoped by the same `date_from`/`date_to` as the report — same pattern every prior report already established. The Net Profit line navigates to the existing, unchanged Profit & Loss report itself, pre-scoped to the same `date_from`/`date_to` — the same "drill up one report level" pattern Balance Sheet's own Current Year Profit row already established (`BALANCE_SHEET_DESIGN.md §9`), reused directly since the date range is already identical (no fiscal-year re-anchoring needed here, per §6).
- Export/Import: same disabled-or-omitted convention every report page in this module already follows (a per-sprint call, not architectural).

---

## 10. Performance

**Aggregation strategy.** `GeneralLedgerService::listAccounts({date_from, date_to})` is exactly two grouped aggregate queries regardless of account count (unchanged, §0) — called **once** by `CashFlowService`, giving both the opening and ending balance every section needs in one pass. `ProfitLossService::summarize()` adds its own usual query count (two `listAccounts`-equivalent aggregates plus one mapping lookup, per `PROFIT_LOSS_DESIGN.md §9` — actually one `listAccounts` call plus one mapping lookup) on top. Total: on the order of **three to four aggregate queries plus two small mapping lookups**, independent of transaction volume — **fewer queries than Balance Sheet needed** (six aggregate-query-equivalents, `BALANCE_SHEET_DESIGN.md §10`), specifically because the Indirect Method's single bounded-range `listAccounts()` call already returns both endpoints Cash Flow needs, where Balance Sheet's unbounded-`date_from` design required two separate `ProfitLossService` sub-calls to split Current Year Profit from Retained Earnings. This is a direct, structural consequence of choosing the Indirect Method (§2) over re-deriving anything.

**No pagination needed** — line-item count is bounded by the Chart of Accounts' Asset/Liability/Equity accounts (a handful today), same reasoning every prior report already used.

**Index usage.** Fully inherited from General Ledger's existing migration — no new index, no new query shape.

**Scalability.** As transaction volume grows, query count stays constant (aggregate queries, not per-line queries) — the same O(1)-in-data-size property every report in this module already has. Nothing in this design scans `journal_entry_lines` row-by-row; every figure comes from an aggregate `SUM()` already grouped by account.

---

## 11. Browser Verification Report

Performed against the running app (`php artisan serve` + `vite dev`) before writing this design, per the ticket's mandatory inspection step:

- **Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet** all confirmed live under "Accounting Reports," five tabs in a shared `SectionNav`, consistent `PageHeader`/`ActionBar`/filter-row-then-table layout across all five — Cash Flow's proposed sixth tab follows the identical, already-five-times-proven growth pattern; nothing about the module's structure needs to change to accommodate it.
- **General Ledger, live, unfiltered**: confirmed `1100 Cash and Bank` sits at `Rp 0` opening/debit/credit/ending — grounding §0's worked example in an observed fact, not an assumption. Confirmed `1200 Accounts Receivable` ending balance `Rp 175.000` and `2100 Tax Payable` ending balance `Rp 20.000` — the exact accounts this design's Operating Activities adjustments (§4) would apply to.
- **Balance Sheet, live**: Total Assets `Rp 175.000` = Total Liabilities and Equity `Rp 175.000` (Balanced pill) — the identity §8's algebraic proof is built on top of, reconfirmed still holding in this session before this design was written.
- **Profit & Loss, live, This Month**: Net Profit `Rp 140.000` — the exact figure this design's Operating Activities section would start from for that same period.
- No console errors or failed network requests observed during inspection; no interaction attempted a write (this sprint touches no create/edit/delete surface).

---

## Open Questions

1. **Investing Activities / long-term Financing have no seeded example** — both are architecturally ready (§4/§5) but will render empty until a future module (Fixed Assets, long-term loans) adds such an account and its own `ReportAccountMapping` row. Not a gap in this design; nothing to build now.
2. **Multiple cash accounts** — only `1100 Cash and Bank` exists today; the design already generalizes to multiple `cash_and_equivalents`-mapped accounts (a future Petty Cash or a second bank account) without any change, since Opening/Closing Cash is already a `sum(...)` over the section, not a single-account read.
3. **Period Closing** (flagged by `PROFIT_LOSS_DESIGN.md`, `TRIAL_BALANCE_DESIGN.md`, and `BALANCE_SHEET_DESIGN.md` as a separate future design) is the same seam §5 already names for Retained Earnings — once real closing entries post to `3100`, this design's exclusion of that account from the `cash_flow` mapping should be revisited alongside Balance Sheet's own transition, not solved independently here.

Stopping here — no code, no migrations, no tests, no frontend pages. Waiting for architectural review.
