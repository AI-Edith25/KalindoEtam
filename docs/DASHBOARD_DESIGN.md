# Dashboard & KPI — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests, no frontend pages until approved.

Frozen inputs this design must not modify: **Accounting Engine, Tax Engine, Administration Module, and every existing report (Trial Balance, Profit & Loss, Balance Sheet, Cash Flow, Purchase/Sales/Inventory Reports)**. This design proposes a **read-only aggregation and presentation layer** over data these modules already produce — one new chart dependency (flagged explicitly, §5), a small number of additive repository methods (date-range totals, never new business rules), and a widget-registry pattern on the frontend. Nothing here recomputes a number any existing report already owns.

## 0. What already exists (grounding) — the central discovery of this sprint

Confirmed by inspecting the running app (Chrome — Dashboard, Sales, Purchase, Inventory, Accounting Reports, Administration) and reading `DashboardController`/`DashboardService`, every repository it depends on, `ProfitLossService`/`GeneralLedgerService`, `StockLedgerService`, `ApprovalFlow`, and the frontend `DashboardPage`/`SummaryCard` before writing anything below:

- **A `DashboardService` already exists and is exactly the right shape** — its own docblock states the discipline this design continues: *"Read-only aggregation over existing entities — no new tables, no business rules. Every number here is derived from data the six workflow modules already produce."* It already injects nine repositories (`Item`, `PurchaseOrder`, `SalesOrder`, `GoodsReceipt`, `Delivery`, `PaymentEntry`, `ReceiptEntry`, `AccountsPayable`, `AccountsReceivable`) and exposes `stockSummary()`, `purchasesForDate()`/`salesForDate()` (single-day only), `accountsPayableOutstanding()`/`accountsReceivableOutstanding()`, `lowStockItems()`, and `recentTransactions()` (a bounded, sorted merge across six document types). This design **extends** this exact class — it does not replace it or introduce a parallel one.
- **The live Dashboard today** (`/dashboard`, already the landing page — `/` already redirects here) renders four `SummaryCard`s (Total Stock on Hand, Zero Stock Items, Outstanding Payable, Outstanding Receivable) plus a `LowStockCard` and `RecentTransactionsCard`. Confirmed live: **89 stock on hand, Rp 0 payable, Rp 358.100 receivable**, one low-stock table (currently empty), and a Recent Transactions feed already correctly merging Purchase Orders, Deliveries, and Sales Orders. It is **one static page for every user regardless of role** — there is no role-awareness anywhere in `DashboardPage.tsx` today.
- **Two of the seven existing `DashboardController` endpoints are already built but never rendered**: `purchasesToday`/`salesToday` (`DashboardService::purchasesForDate()`/`salesForDate()`) have no corresponding card on the live page. This is the same "backend ships ahead of the UI" pattern this whole design series keeps finding (Tax, Currency, Company, User backend before Sprint 22B) — a Sales/Purchase Summary widget is largely **wiring up what already exists**, not new backend work.
- **No date-range aggregation exists anywhere.** Every repository method that touches a date (`totalForDate`, `stockSummary`) is single-day or point-in-time. `PurchaseReportPage`/`SalesReportPage` (the existing "Reports" section) filter and list individual documents — they do not aggregate a series. A **Sales Trend / Purchase Trend chart needs one new method each** (`totalsByDateRange`, grouped by day) on `SalesOrderRepository`/`PurchaseOrderRepository` — additive to the exact pattern `totalForDate` already established, not a new calculation concept.
- **`ProfitLossService::summarize($filters)` already computes Revenue/Expense/Net Profit** for any date range, delegating all balance math to `GeneralLedgerService::listAccounts()` — confirmed by reading the full class. This is the **only** correct source for a Financial Summary widget or a Revenue vs Expense chart; recomputing Revenue/Expense from raw journal entries inside `DashboardService` would be exactly the "second calculation" every prior report in this codebase (`PROFIT_LOSS_DESIGN.md`, `CASH_FLOW_DESIGN.md`) has deliberately refused to do.
- **`StockLedgerService::listAll($filters, $perPage)` and `currentBalances()` already exist** (confirmed — built for the live Inventory → Stock Ledger page) and already carry each movement's direction and date. An Inventory Movement chart reuses this listing, aggregated by date and direction, not a new stock-tracking mechanism.
- **No chart library is installed** — grepped `package.json` for `recharts`, `chart.js`, `victory`, `d3`, `visx`: none present. Every chart this design recommends (§5) requires **one new, small frontend dependency** — stated plainly here rather than glossed over, the same "genuinely new, not reuse" honesty `TAX_ENGINE_DESIGN.md §2`/`§9` used for its own new enum cases and columns.
- **Roles & Permissions (Sprint 22B) is the exact mechanism role-awareness should ride on.** Permissions already follow a `{module}.{action}` convention (`sales_order.view`, `purchase_order.view`, `item.view`, `journal_entry.view`, …) and the logged-in user's roles/permissions are already available client-side (`useAuth().user.roles`, populated from `/auth/me`). **Today only one role exists in the seeded system — `Admin`, holding all permissions** (confirmed live in Administration → Roles & Permissions: 2 roles total, `Admin` and a test `Limited` role created during Sprint 22B's own verification). The four roles this ticket names — Owner, Sales, Warehouse, Accountant — **do not exist as real `Role` rows yet**. This design's job is to make the dashboard automatically correct the moment those roles are created through the already-shipped Roles & Permissions UI, not to pre-seed them now.
- **`ApprovalFlow` already exists as dormant, fully-shaped infrastructure** — its own docblock says *"Structure only — no approval workflow logic is implemented yet,"* and every `Documentable` entity (Invoice, PO, Sales Order, …) already has a wired `approvalFlows()` relation via the shared `Documentable` trait — but nothing ever creates or reads an `ApprovalFlow` row today. Directly relevant to §6's Approval Queue extension — the same "dormant infrastructure waiting for a UI" shape this entire design series keeps activating.
- **Shared components confirmed available for reuse**: `SummaryCard` (already generic — title/value/description/icon/tone), `DataTable`, `EmptyState`, `Skeleton`-based loading states, the existing responsive grid (`grid gap-4 sm:grid-cols-2 lg:grid-cols-4`) the current Dashboard already uses. No new card/table primitive is proposed.

---

## 1. Dashboard Architecture

**Core decision: one `DashboardPage`, one widget registry, zero per-role page implementations.** A widget is a small declarative record — id, title, the component that renders it, which permission(s) gate its visibility, and a default layout size. `DashboardPage` fetches the current user's permissions (already available, Sprint 22B) once, filters the registry down to the widgets that user can actually see, and renders what's left in a responsive grid. Adding a role never touches this code — it only ever changes *which permissions that role holds*, which is already a solved problem (Roles & Permissions UI).

```
                    ┌────────────────────┐
                    │  Widget Registry    │   id, title, component,
                    │  (static, frontend) │   requiredPermission, size
                    └──────────┬──────────┘
                               │
                    user.permissions (already
                    fetched at login, §0)
                               │
                               ▼
                    ┌────────────────────┐
                    │   DashboardPage     │  filters registry →
                    │                      │  renders visible widgets
                    └──────────┬──────────┘  in a responsive grid
                               │
              ┌────────────────┼─────────────────┐
              ▼                ▼                  ▼
     SummaryCard-shaped   Chart widgets      Table widgets (Recent
     KPI widgets (§3)     (§5, new dep)      Activities/Pending Tasks)
              │                │                  │
              └────────────────┴──────────────────┘
                               │
                    DashboardService (extended, §3) —
                    still the only place dashboard data
                    is aggregated; still zero new
                    business rules
```

This mirrors the exact "one calculation/aggregation service, many independent consumers" shape `GeneralLedgerService` already has relative to Trial Balance/P&L/Balance Sheet/Cash Flow (`CASH_FLOW_DESIGN.md §1`), and the "one Administration page, four permission-gated tabs" shape `ADMINISTRATION_DESIGN.md §1` already established for a different kind of role-driven visibility. Nothing new is invented — this is the same pattern applied a third time.

---

## 2. Widget Layout

Reuses exactly what the live Dashboard already uses — no new primitive:

- **KPI cards**: the existing `SummaryCard` component, unchanged (title, value, description, icon, tone). Every KPI in §3 renders through it.
- **Grid**: the existing responsive grid (`sm:grid-cols-2 lg:grid-cols-4` for cards, `lg:grid-cols-2` for wider widgets like charts/tables) — a widget's declared `size` (`sm`/`md`/`lg`) maps to a column-span, the same breakpoints already in use.
- **Charts** (§5): rendered inside a plain `Card` (existing component), same visual chrome as every other widget — a chart is a widget like any other, not a special layout region.
- **Tables** (Recent Activities, Pending Tasks): the existing `DataTable` component inside a `Card`, identical to how `RecentTransactionsCard` already works today.
- **Responsive behavior**: unchanged from today's live grid — cards stack to one column on mobile, two on tablet, four on desktop; wide widgets (charts, tables) always span at least half the grid. No new breakpoint system.
- **Empty states**: the existing `EmptyState` component (already used by `DataTable` when a list is empty, and already what `LowStockCard` shows live today — "No items are low on stock."). A KPI card with no data yet (e.g. no roles seeded, no sales this period) shows `0`/`Rp 0` with its existing description text, not a blank card — the same graceful-zero behavior the live Outstanding Payable card already has today (`Rp 0`, confirmed live).

---

## 3. KPI Strategy

Every KPI below is mapped to the existing service that must supply it — no widget invents its own query.

| Widget | KPI | Existing source | New work |
|---|---|---|---|
| **Sales Summary** | Today's/period sales total, order count | `DashboardService::salesForDate()` (exists, unused in UI today) | Wire into a card; extend with a period variant reusing the same repository call pattern |
| **Purchase Summary** | Today's/period purchase total, order count | `DashboardService::purchasesForDate()` (exists, unused in UI today) | Same — wire up what's already built |
| **Inventory Summary** | Stock on hand, zero-stock count, low-stock count | `DashboardService::stockSummary()` + `lowStockItems()` (both exist, already rendered live) | None — already fully live |
| **Financial Summary** | Revenue, Expense, Net Profit (period), AP/AR outstanding | `ProfitLossService::summarize($filters)` (exists, reused as-is) + `accountsPayableOutstanding()`/`accountsReceivableOutstanding()` (exist, already rendered live) | `DashboardService` gains a thin `financialSummary()` method that calls `ProfitLossService::summarize()` and extracts the three totals — no GL math duplicated |
| **Recent Activities** | Latest documents across modules | `DashboardService::recentTransactions()` (exists, already rendered live) | Optionally extend the merge to include `AuditLog` entries (Sprint 22B) for admin-facing activity — additive to the existing merge, same pattern |
| **Pending Tasks** | Draft/unsubmitted counts per module admin cares about (draft POs, draft Sales Orders, unposted Journal Entries) | Existing repositories' own `status` column (`DocumentStatus::DRAFT`, already how every list page's Status filter works) | New `DashboardService::pendingTasks()` — a `count()` per repository filtered to `status = draft`, the same read-only shape every other `DashboardService` method already has |

No KPI in this table requires a new table, a new business rule, or a bypass of an existing service. The two genuinely new pieces are `financialSummary()` (a thin wrapper around `ProfitLossService`) and `pendingTasks()` (a `count()` query using a status column every module already has) — both additive methods on the *existing* `DashboardService`, not a new service.

---

## 4. Role Strategy

**Recommendation: permission-gated widgets, not role-named dashboards.** A widget declares the permission(s) it needs (reusing Sprint 22B's exact `{module}.{action}` names); the dashboard shows whatever the current user's role grants. This is a direct extension of the Roles & Permissions matrix already shipped — no second "dashboard role" concept, no switch statement on role name anywhere in the frontend.

| Widget | Gated on (existing permission) |
|---|---|
| Sales Summary, Sales Trend | `sales_order.view` |
| Purchase Summary, Purchase Trend | `purchase_order.view` |
| Inventory Summary, Inventory Movement | `item.view` or `stock.view` |
| Financial Summary, Revenue vs Expense | `journal_entry.view` (the same permission that already gates the Accounting Reports section) |
| Recent Activities | union of whatever document permissions the user already has — a user only sees activity for modules they can already open |
| Pending Tasks | same per-module permission as that module's own summary widget |
| Audit Log excerpt (admin-facing) | `audit_log.view` |

Recommended default sets per named role (once those roles are actually created — §0's honest caveat applies: **only `Admin` exists today**):

- **Administrator**: everything — matches `Admin` already holding all 128 permissions (confirmed live, Sprint 22B).
- **Owner**: Financial Summary, Sales Summary, Purchase Summary, Revenue vs Expense chart, Pending Tasks — strategic overview, not operational line-items.
- **Sales**: Sales Summary, Sales Trend chart, Recent Activities (sales-scoped), Pending Tasks (draft Sales Orders/unconfirmed Deliveries).
- **Warehouse**: Inventory Summary, Inventory Movement chart, Low Stock table, Pending Tasks (pending Goods Receipts/Deliveries).
- **Accountant**: Financial Summary, AP/AR outstanding, Revenue vs Expense chart, Recent Activities (accounting-scoped).

These five sets are **recommendations for how those roles' permissions should be configured** when created — not five dashboard components. The exact same `DashboardPage` renders all five, because it never branches on role name, only on permission.

---

## 5. Charts

Only the ticket's four are recommended — no decorative chart is proposed, and each is justified by an operational question a static number can't answer:

- **Sales Trend** — daily/weekly sales totals over a period. *Why*: a single "today's sales" number can't show whether this week is trending up or down, or whether a slow day is an anomaly or a pattern — exactly the gap between a KPI card and a report. Source: new `SalesOrderRepository::totalsByDateRange()`.
- **Purchase Trend** — same shape, Purchase side. *Why*: shows restocking rhythm and spend pace over time, catching an unusual spike before it shows up in a monthly report. Source: new `PurchaseOrderRepository::totalsByDateRange()`.
- **Revenue vs Expense** — Revenue and Expense plotted together over a period (e.g. last 6 months). *Why*: gives an at-a-glance profitability trend without opening the full Profit & Loss report — the dashboard's whole purpose (§ objective: "summarize... rather than replace detailed reports"). Source: `ProfitLossService::summarize()` called once per period bucket, reusing its existing Revenue/Expense totals — zero new GL logic, confirmed reusable in §0.
- **Inventory Movement** — stock in vs stock out over time, by day. *Why*: distinguishes "low stock because of a genuine sales spike" from "low stock because Goods Receipt is running late" — a distinction the current single "current stock" number structurally cannot make. Source: existing `StockLedgerService::listAll()`, aggregated by date and direction.

**Explicitly not recommended**: any chart with no decision behind it (e.g. a pie chart of item categories, a gauge for "stock health score") — these would be exactly the decorative-chart pattern the ticket warns against, with no existing service backing them anyway.

**New dependency, stated plainly**: no charting library exists in this codebase today (§0). This design recommends **Recharts** (SVG-based, tree-shakeable, the most common React charting choice, no canvas/WebGL runtime) as the implementation detail for a future sprint — named here so the "one new dependency" cost is visible to the reviewer now, not discovered during implementation.

---

## 6. Navigation Flow

**Dashboard stays the landing page — no change.** `/` already redirects to `/dashboard`, and Dashboard is already first in the sidebar. This design adds nothing here structurally.

**Drill-down, by widget type**:
- A KPI card navigates to its module's existing list page, filtered where the filter already exists (e.g. Outstanding Receivable → `/finance/incoming`; Zero Stock Items → `/inventory/stock-balance`).
- A chart data point navigates to the matching Report page, pre-filtered to that date/period (e.g. clicking a Sales Trend day → `/reports/sales?date=...`, the existing Sales Report page, not a new one).
- A Recent Activities/Pending Tasks row navigates directly to that document's existing detail page (`/sales/orders/{id}`, `/purchase/orders/{id}`, …) — the same links `RecentTransactionsCard` already implies via its document numbers, made clickable.

This is the same relationship `TAX_ENGINE_DESIGN.md §1` established between calculation and posting, applied to summarization and detail: **the Dashboard never becomes a second copy of a report — it's the shortcut into the one real report that already exists.**

---

## 7. Future Expansion Strategy

- **Notifications** — the widget registry already separates "what can exist" from "what's on screen right now." A Notifications widget is one more registry entry, sourced from a future notifications service/table, gated by its own permission if needed. No restructuring of `DashboardPage` — it already renders whatever the registry (filtered by permission) hands it.
- **Approval Queue** — has a direct, concrete precursor already sitting in this codebase: `ApprovalFlow` (§0) is fully modeled and already related to every document type via `Documentable::approvalFlows()`, but never populated. This design's own **Pending Tasks** widget (§3) is the structural sibling — an Approval Queue is Pending Tasks filtered to "awaiting *my* decision" once `ApprovalFlow` rows are actually created by a future workflow sprint. Zero dashboard redesign; the widget slot and the permission-gating mechanism already exist.
- **AI Insights** — a widget that calls a future summarization/anomaly-detection service and renders text or highlighted figures, gated by its own permission like every other widget. The registry doesn't care whether a widget's data source is a repository aggregate or a model inference — same slot, same rendering contract.

All three extend the registry's *edges* (new entries, new permissions) without touching §1's core shape — the same "additive, not a rebuild" discipline `ADMINISTRATION_DESIGN.md §7` used for Multi-Company/SSO/2FA.

---

## 8. Browser Verification Report

Performed against the running app (`php artisan serve` + `vite dev`, admin session) before writing this design, per the ticket's mandatory inspection step:

- **Dashboard, live**: confirmed exactly four `SummaryCard`s (Total Stock on Hand: 89, Zero Stock Items: 0, Outstanding Payable: Rp 0, Outstanding Receivable: Rp 358.100), a Low Stock Items table (empty, correct empty-state message), and a Recent Transactions table correctly merging Purchase Order/Delivery/Sales Order rows sorted by recency. No charts, no financial (Revenue/Expense) figures, no role-based differences — one static page.
- **Sales, live**: confirmed sub-nav (Orders/Deliveries/Invoices/Credit Notes/Debit Notes) and a working Sales Orders list (3 orders, e.g. `SO-00002` submitted, 10/10 delivered, Rp 100.000) — the exact shape a Sales Trend/Summary widget would drill into.
- **Purchase, live**: confirmed sub-nav (Orders/Goods Receipts) and one live Purchase Order (`PO-00001`, Draft, Rp 10.000) — a live, correct example of what a "Pending Tasks" (draft PO) widget would surface today.
- **Inventory, live**: confirmed sub-nav (Stock Balance/Stock Ledger/Adjustments) and one stocked item (`ITM-1 — Widget`, Main WH, 89 on hand) — matches the Dashboard's own stock figure exactly, confirming both already read the same underlying data.
- **Accounting Reports, live**: confirmed sub-nav (Journal Entries/General Ledger/Trial Balance/Profit & Loss/Balance Sheet/Cash Flow/Period Closing) and 10 live posted Journal Entries — the real data a Financial Summary widget and a Revenue vs Expense chart would summarize via `ProfitLossService`, confirmed reachable and non-empty.
- **Administration, live**: confirmed Company/Users/Roles & Permissions/Audit Log all still functioning post-Sprint-22B (no regression), and confirmed directly in the Roles & Permissions tab that **only two roles exist today — `Admin` and a test `Limited` role** — grounding §4's honest caveat in an observed fact, not an assumption.
- No console errors observed during this inspection; no write actions were attempted (design-only sprint).

**Post-design fit check**: the proposed widget registry renders inside the Dashboard's own existing grid container — verified by comparing the live grid's column classes (`sm:grid-cols-2 lg:grid-cols-4`) against this design's widget `size` mapping (§2): every existing card fits the `sm` size unchanged, and the two existing wide cards (Low Stock, Recent Transactions) already occupy the `lg:grid-cols-2` row this design reuses for charts and tables. No layout change is required for the widgets that already exist today to keep working exactly as they do now.

---

## Open Questions

1. **`totalsByDateRange()` bucket granularity** — daily buckets by default (matches how every existing report's own date filters already work), with weekly/monthly rollup left as a frontend aggregation of daily data rather than a second backend method. Not fully resolved — flagged for reviewer input, the same "flagged, not solved" discipline this series already uses.
2. **Recharts vs an alternative** — Recharts is recommended (§5) as the lowest-friction choice given this stack (React + Tailwind, no existing charting convention to match), but the final pick belongs to whoever implements this design.
3. **Whether `pendingTasks()` should be per-user (assigned to me) or per-module (all drafts)** — this design defaults to per-module (matches how every list page's Status filter already works, no "assigned to" concept exists anywhere in this codebase yet) — per-user awaits the Approval Queue work named in §7.

Stopping here — no code, no migrations, no tests, no frontend pages. Waiting for architectural review.
