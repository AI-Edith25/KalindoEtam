# Frontend — Inventory Module

Phase 2G. The operational center of the ERP: everywhere else (Goods Receipt, Delivery, and now Stock Adjustment) *writes* to the Stock Ledger; this module is where a user *reads* it back — as a raw transaction log (Stock Ledger), as a current-state report (Stock Balance), and as a place to correct it when reality disagrees with the system (Stock Adjustment). Per the sprint's explicit constraint, nothing here duplicates business logic: Stock Balance is a query over the ledger, not a new table, and Stock Adjustment writes stock exclusively through the same `StockLedgerService` every other module already uses.

This was the first sprint since Delivery to require new backend schema (`.ia/07_CLAUDE_WORKFLOW.md`'s explain→wait-for-approval gate), and the plan was reviewed for concurrency correctness before implementation — see §5 for the race condition that review caught and how it was fixed.

---

## 1. Architecture

```
features/inventory/
  navigation.ts                  inventorySectionNav — Stock Balance / Stock Ledger / Adjustments, all 3 from day one
  types.ts                       StockLedgerEntry, StockBalanceRow, StockAdjustment(+Item), filter value types
  lib/
    voucherLinks.ts               resolveVoucherLink(voucherType, voucherId) → route or null
    stockLedgerFilters.ts, stockBalanceFilters.ts, stockAdjustmentFilters.ts
    stockAdjustmentFormSchema.ts  zod — string-then-convert countedQty, required reason per line
  api/
    stockLedgerApi.ts             fetchStockLedgerEntries(params) → GET /stock-ledger
    stockBalanceApi.ts            fetchStockBalanceReport(params) → GET /stock-ledger/balances/report
    stockAdjustmentApi.ts         full CRUD + submit, no cancel
    stockApi.ts                   (pre-existing, from Phase 2F) fetchStockBalances — bulk lookup, reused here unchanged
  components/
    StockLedgerFiltersBar.tsx, StockBalanceFiltersBar.tsx, StockAdjustmentFiltersBar.tsx
    StockAdjustmentLineItemTable.tsx   free-form field array, System Qty / Physical Qty / Difference / Reason
  pages/
    StockBalanceListPage.tsx      Inventory's landing page
    StockLedgerListPage.tsx       reads ?item_id=&warehouse_id= from useSearchParams for cross-navigation
    StockAdjustmentListPage.tsx / EditorPage.tsx / DetailPage.tsx
```

Backend additions (all additive — see §2 and §4 for the two genuinely new pieces):

```
app/Http/Requests/IndexStockLedgerRequest.php          new
app/Http/Requests/IndexStockBalanceReportRequest.php    new (distinct from the existing IndexStockBalanceRequest)
app/Repositories/StockLedgerRepository.php              +search(), +currentBalances(), +latestBalanceUnlocked()
app/Services/StockLedgerService.php                     +listAll(), +currentBalances(), +peekBalance(), +recordToBalance()
app/Http/Controllers/Api/V1/StockLedgerController.php   +list(), +balancesReport() — existing index()/balances() untouched
app/Http/Resources/StockBalanceResource.php             new
app/Http/Resources/StockLedgerResource.php              extended with nested item/warehouse

database/migrations/..._create_stock_adjustments_table.php        new
database/migrations/..._create_stock_adjustment_items_table.php   new
app/Models/StockAdjustment.php, StockAdjustmentItem.php            new
app/Repositories/StockAdjustmentRepository.php, StockAdjustmentItemRepository.php   new
app/Services/StockAdjustmentService.php                             new
app/Http/Controllers/Api/V1/StockAdjustmentController.php           new
app/Http/Requests/Store/Update/IndexStockAdjustmentRequest.php      new
app/Http/Resources/StockAdjustmentResource.php, StockAdjustmentItemResource.php   new
app/Enums/StockVoucherType.php                          +STOCK_ADJUSTMENT case
database/seeders/DocumentEngineSeeder.php                +ADJ- naming series row
routes/api.php                                           +GET /stock-ledger, +GET /stock-ledger/balances/report,
                                                           +apiResource stock-adjustments, +submit (no cancel)
```

Routes (`app/router.tsx`): `/inventory/stock-balance`, `/inventory/stock-ledger`, `/inventory/adjustments`, `/inventory/adjustments/new`, `/inventory/adjustments/:id/edit`, `/inventory/adjustments/:id`. The sidebar's "Inventory" link, previously a dead placeholder (`/inventory`, falling through the router's catch-all to `/dashboard`), now points at `/inventory/stock-balance` — mirroring how Purchase lands on `/purchase/orders` and Sales on `/sales/orders`.

---

## 2. Stock Ledger

`GET /stock-ledger` — every ledger entry, across every item and warehouse, paginated and filterable (search, `warehouse_id`, `item_id`, `date_from`/`date_to`). This didn't exist before this sprint; only a single-item-scoped `GET /items/{item}/stock-ledger` did. `StockLedgerRepository::search()` is a new sibling method (the existing `historyForItem()` is untouched), eager-loading `item`/`warehouse` so the list can show names without a client-side lookup-join (item catalogs can exceed the page-1 lookup ceiling used elsewhere in this app).

Frontend columns: Date, Item, Warehouse, Reference Document, Movement Type, Qty In, Qty Out, Running Balance.

- **Qty In / Qty Out** are derived client-side from `qty_change`'s sign — no backend change needed, the column was already signed.
- **Movement Type** reuses `StatusBadge`, extended with three new entries (`in`/`out`/`adjustment`) in its existing `STATUS_STYLES` map — the same extensible-map pattern already used for `waiting`/`partial`/`completed` in Phase 2C.1, not a new badge component.
- **Reference Document** is clickable via `resolveVoucherLink(voucherType, voucherId)`, a pure function switching on `voucher_type`: `goods_receipt` → Goods Receipt Detail, `delivery` → Delivery Detail, `stock_adjustment` → Stock Adjustment Detail, `stock_in` → `null` (no frontend page exists for Stock In yet, so it renders as plain text rather than linking to a route that would silently fall through the router's catch-all — the same "don't link to a page that doesn't exist" principle already applied once in Phase 2E). `voucher_id` is already the target document's own id in every existing writer (`GoodsReceiptService`, `DeliveryService` both pass `voucherId: $document->id`), so no backend field was needed to make this work — `reference_no` (the document number) was already exposed too.

---

## 3. Stock Balance

`GET /stock-ledger/balances/report` — one row per (item, warehouse) pair with any ledger history, i.e. "what do we have, where." Deliberately **not** the same endpoint as the existing `GET /stock-ledger/balances` (Delivery's bulk lookup by explicit `item_ids[]`, unchanged) — different shape, different route, same controller (see design decision in §6).

No balances table exists in this schema; a balance is always "the latest `stock_ledgers` row for that pair." Enumerating every pair for a paginated report can't loop `latestBalance()` per pair the way `totalBalanceForItem()` does for one item (N+1, doesn't paginate) — `StockLedgerRepository::currentBalances()` instead ranks rows with a MySQL 8 window function (`ROW_NUMBER() OVER (PARTITION BY item_id, warehouse_id ORDER BY posting_datetime DESC, id DESC)`), keeps only `rn = 1`, and joins `items`/`warehouses` for filtering and display — the first use of this query shape in the codebase, built on the Eloquent query builder (`selectRaw`) rather than a bare `DB::table()`, following the one existing precedent for raw SQL fragments in this codebase (`AccountsPayableRepository`).

Frontend columns: Item, Warehouse, Current Qty, Reserved Qty, Available Qty, Reorder Level. **Reserved Qty and Reorder Level are always `—`** — `StockBalanceResource` returns `null` for both, explicitly, per the sprint brief ("placeholder if backend does not yet support it"). Neither concept exists anywhere in this schema; nothing was fabricated to fill the columns.

Filters: search, warehouse, item group (joined through `items.item_group_id`). Row click navigates to `/inventory/stock-ledger?item_id=&warehouse_id=` — the cross-navigation described in §7.

---

## 4. Stock Adjustment

A genuinely new document module — the first new schema since Delivery — following the same Documentable lifecycle as every other transactional document (draft → submit, `ADJ-00001` numbering via the same `NamingSeries` mechanism as `PO-`/`SO-`/`GR-`/`DN-`).

### Workflow

```
Select Warehouse → Add Row → Select Item → System Qty loads (existing bulk balance endpoint)
        ↓
Enter Physical Qty → Difference computed client-side → Reason (required)
        ↓
Record Adjustment (draft — captures the count, moves nothing)
        ↓
Confirm Adjustment (submit — writes Stock Ledger entries, stock now matches what was counted)
```

Line items are **free-form** (Add/Remove Row, item lookup), not derived from a parent document — a physical count can include any item in the warehouse, unlike Goods Receipt/Delivery which originate from a specific PO/SO. `StockAdjustmentLineItemTable` is modeled on `PurchaseOrderLineItemTable`'s shape for this reason, not `GoodsReceiptLineItemTable`'s fixed-row shape.

### Never edits Current Stock directly

`StockAdjustmentService` never touches `Item.current_stock`. `create()`/`update()` only ever call `StockLedgerService::peekBalance()` (read-only, for the displayed `system_qty` snapshot); `submit()` is the only place `StockLedgerService::recordToBalance()` (the one write path) is called. Every stock write in this entire application — Goods Receipt, Delivery, and now Stock Adjustment — funnels through exactly one service.

### Concurrency-safe reconciliation

The first draft of `submit()` computed the ledger delta from an *unlocked* read (`getCurrentBalance()`), then wrote it via the existing `record()` in a *separately* locked transaction — two other transactions could interleave between the read and the write, silently desyncing the result from what the user actually counted. Caught in a design review before any code was written. Fixed with one new method, `StockLedgerService::recordToBalance(itemId, warehouseId, targetBalance, ...)`, which takes the locked read (`latestBalance()`, `FOR UPDATE`) and the write inside the *same* transaction — the resulting `balance_qty` is therefore always exactly `targetBalance` (= `counted_qty`), regardless of concurrent activity elsewhere. `record()` and `getCurrentBalance()` themselves were never touched, so Goods Receipt and Delivery carry zero risk from this change.

Lines where the physical count already matches the system (`qtyChange === 0`) write **no ledger entry** — `recordToBalance()` returns `null` and `submit()` skips it. Verified directly: a zero-difference adjustment (ADJ-00002 in testing) submitted successfully with `Item.current_stock` unchanged and the `stock_ledgers` row count unchanged, while non-zero adjustments (ADJ-00001: +3, ADJ-00003: −5) each produced exactly one new entry with `transaction_type: adjustment`, `voucher_type: stock_adjustment`.

### Snapshot fields, deliberately capped

`stock_adjustment_items` snapshots `item_code`/`item_name`/`uom` — matching the explicit "don't rely solely on the live Item relation for historical accuracy" convention already shared by `goods_receipt_items`/`delivery_items` — but **only** those three fields, not left open to grow into fuller denormalization. The goal is historical display, not a second copy of the Item table.

### Reason is per-line, required, and `text` not `string(255)`

The sprint's workflow diagram ("System Qty → Physical Qty → Difference → Reason → Submit") mirrors every other document in this system being multi-line, and different items in one adjustment can have different causes (miscount vs. damaged vs. expired) — a single header-level reason would lose that. It stays a real requirement, not busywork, because rows are added **deliberately** (free-form, not the entire warehouse catalog pre-populated) — a user only adds a row for an item they're actually adjusting, so every row is inherently a discrepancy being recorded. Stored as `text` rather than `string(255)` since physical-count explanations often run longer than a single line, at negligible storage cost.

### No cancel

`StockAdjustment::cancel()` unconditionally throws, same pattern as `Delivery::cancel()`/`GoodsReceipt::cancel()` — ledger entries are immutable once written, and no reversal workflow exists yet. No cancel route on the controller; no Cancel button anywhere in the frontend, for any status.

---

## 5. A Real Reactivity Bug, Found and Fixed During Verification

`StockAdjustmentEditorPage` originally derived `warehouseId`/`watchedItems` (and from them, the `itemIds` driving the stock-balance query) via `form.watch('warehouse_id')` / `form.watch('items')` called directly in the render body — the exact pattern already used successfully in `PurchaseOrderEditorPage`, `SalesOrderEditorPage`, `GoodsReceiptEditorPage`, and `DeliveryEditorPage` for computing live totals.

In this component specifically, it didn't reliably work: selecting an item in the free-form `StockAdjustmentLineItemTable` (a **child** component, via `setValue()` inside its own `onValueChange` handler) would visibly update the Select's displayed value, but the **parent**'s `form.watch('items')` would not consistently pick up the change — `itemIds` stayed empty, the stock-balance query never fired (confirmed via network inspection: zero requests), and System Qty stayed stuck at `0` indefinitely. Reproduced repeatedly across multiple clean test sessions, ruling out network latency.

Every prior editor's use of `form.watch()` only had to react to normal `<input>` typing inside a `Controller`-registered field in the *same* render tree; none of them had a query in the *parent* keyed off a value set programmatically from a *child* component's `Select.onValueChange`. That specific cross-component chain had never been exercised before this component.

**Fix:** switch both reads in the parent from `form.watch(...)` to `useWatch({ control: form.control, name: ... })` — the same hook already used successfully at the *child* level in `DeliveryLineItemTable`'s and this component's own `useWatch({ control, name: 'items' })` for displaying `systemQty` reactively. After the change, System Qty populated correctly and consistently across every retest, including the full create → submit cycle verified end-to-end in the browser.

**Takeaway for future editors with a similar shape** (a parent-level query whose key depends on a value a free-form child component sets programmatically): prefer `useWatch` over `form.watch()` for that specific read. This is the second RHF reactivity subtlety this project has hit in two sprints — Phase 2F found that `useFieldArray`'s own `fields` snapshot doesn't react to plain `setValue()` calls (fixed by reading via `useWatch` at the child level); this one is the same lesson one level up, at the parent.

---

## 6. Design Decisions

- **Stock Balance stays on `StockLedgerController`, no separate controller.** Stock Balance is a *report over* the ledger, not an independent domain resource — everything ledger-derived and read-only lives on one controller (`index()` for the existing single-item history, `balances()` for the existing bulk lookup, `list()` and `balancesReport()` new). The two "balance" endpoints get **separate routes** (`/stock-ledger/balances` vs `/stock-ledger/balances/report`) rather than one branching action, specifically so a future change to the new report's shape can never regress the endpoint Delivery already depends on, and vice versa.
- **`fetchStockBalances` (bulk lookup, Phase 2F) is reused unchanged for Stock Adjustment's System Qty column** — the same endpoint built for Delivery's "Available Stock," now serving a second, unrelated editor exactly as its own code comment predicted it would ("Reusable by any future editor that needs to preview available stock before submitting... e.g., Stock Adjustment"). Zero backend or API-client changes needed for this reuse.
- **No standalone detail page for a single Stock Ledger row or Stock Balance row.** A ledger entry's real "detail" is the reference document it points to (one click away via `resolveVoucherLink`); a balance row's real "detail" is its ledger history (one click away via the cross-navigation in §7). Building separate pages for either would just re-show the same handful of fields already visible in the row.
- **`inventorySectionNav` ships with all 3 tabs from day one**, unlike Purchase/Sales where `SectionNav` was retrofitted after a second sibling document type arrived in a later sprint — this module's three pages (Stock Balance, Stock Ledger, Adjustments) were all part of the same sprint, so there was no "only one sibling exists yet" period to respect.

---

## 7. Navigation Between Inventory and Transaction Modules

Bidirectional, in both directions, each one click:

- **Transaction document → Inventory**: every Goods Receipt, Delivery, and Stock Adjustment submission writes ledger entries with `voucher_type`/`voucher_id` pointing back at itself — visible as a Reference Document link on any Stock Ledger row.
- **Inventory → transaction document**: clicking a Stock Ledger row's Reference Document navigates straight to that Goods Receipt/Delivery/Stock Adjustment's own Detail page.
- **Stock Balance → Stock Ledger**: clicking a balance row navigates to `/inventory/stock-ledger?item_id=&warehouse_id=`, and `StockLedgerListPage` reads those query params via `useSearchParams` on mount to pre-populate its filters — "what's on hand" and "how did it get there" are one click apart, both ways shareable as real URLs.

---

## 8. UX Refinement — Stock Adjustment List Summary Columns

A later, presentation-only refinement (no backend change, no workflow change): the Stock Adjustment List originally showed only a `Lines` count, which told a user *how many* items were in a document but nothing about whether counting them actually changed anything — answering that required opening the Detail page every time. Two columns were added to close that gap, both derived client-side from data the list response already carried (`items[].counted_qty`/`system_qty`/`difference_qty`, present since §4 — the list already eager-loads `items`, so this required zero repository, service, or API changes).

- **Physical / System** (e.g. `95 / 100`) shows both raw numbers together in one compact column, deliberately not split into two — a Stock Adjustment can cover multiple items, so the list sums each side across every line (`adjustmentTotals()` in `StockAdjustmentListPage.tsx`) into one representative pair per document, the same "one glance, no drill-in" goal as every other list refinement in this project.
- **Difference** (`+3` / `0` / `−5`) reuses the exact color convention already established for this exact value in `StockAdjustmentDetailPage`/`StockAdjustmentLineItemTable` (green for a surplus, `text-destructive` for a shortfall, default text color at zero) — no new color language introduced, just applied one level up at the document-summary level.

**Why a "Balance Status" badge was intentionally left out.** An earlier pass added one (`Balanced`/`Not Balanced`, derived from `difference === 0`), but it was reverted — the document's own existence already carries that information. A Stock Adjustment is, by definition, an exception/correction document: someone counted stock and is recording what they found. Whether that count happened to match the system (`0`) or not (any other number) is already stated plainly by the `Difference` value sitting right next to it — a badge repeating the same fact in a different shape is redundant, not additive, and one more colored chip competing for attention on a row that's already dense. The `Difference` column alone answers "was this balanced" at a glance (zero vs. non-zero, and non-zero already carries its own color and sign) without a second visual layer saying the same thing. Document Status (`Draft`/`Submitted`) was left completely untouched — no relabeling, no second status concept introduced next to it.

**How this improves operational monitoring while keeping the UI clean.** A warehouse manager scanning the list can now answer "which of these adjustments actually corrected something, and by how much" without opening a single Detail page — a `Difference` of `0` says the count confirmed the system was right; any colored, signed number says a real correction happened and exactly how large it was. Two focused columns (not three) keep the row scannable rather than adding a badge whose entire content was already implied by the number beside it.

Component modified: `frontend/src/features/inventory/pages/StockAdjustmentListPage.tsx` (two new columns: `Physical / System`, `Difference`; one local `adjustmentTotals()` helper; the `Status` column is untouched). No other file touched — Detail page, Editor, `StatusBadge.tsx`, backend, and every other Inventory page are unchanged.

---

## 9. Screenshots

- Stock Balance List (2 items, Reserved Qty/Reorder Level both `—`): `screenshot-1784289175343-48.jpg`
- Stock Ledger List (11 entries, Movement Type badges, all Reference Documents linked): `screenshot-1784289222603-49.jpg`
- Stock Ledger List, cross-filtered from Stock Balance (`?item_id=&warehouse_id=`): `screenshot-1784280387361-38.jpg`
- Stock Adjustment List (3 adjustments, all Submitted): `screenshot-1784289127215-47.jpg`
- Stock Adjustment List after the summary-columns refinement (Physical/System and Difference alongside the unchanged Status column, no Balance Status badge): `screenshot-1784300661995-51.jpg`
- Stock Adjustment Editor (ITM01 selected, System Qty 100 resolved live, Physical Qty 95, Difference −5, Reason filled): `screenshot-1784288913411-45.jpg`
- Stock Adjustment Detail (ADJ-00003, Submitted, "Adjustment confirmed — stock updated" toast): `screenshot-1784289078187-46.jpg`

Verified end-to-end beyond the screenshots: created and submitted three Stock Adjustments (ADJ-00001 +3, ADJ-00002 ±0, ADJ-00003 −5) via both direct API calls and the full browser UI; confirmed `Item.current_stock` tracked each correctly (97→100→100→95); confirmed the zero-difference adjustment wrote no ledger entry while the other two each wrote exactly one; confirmed `stock-adjustments/{id}/cancel` returns 404 (no such route); confirmed validation rejects missing items, negative `counted_qty`, and missing `reason`.

---

## 10. Recommendation for the Next Phase

With Purchase, Sales, and now Inventory complete, every physical stock movement in the system is visible end-to-end — but nothing yet closes the **financial** loop this data has been quietly building since Phase 2D: every Goods Receipt creates an unpaid Accounts Payable record, every Delivery creates an unpaid Accounts Receivable record, and both are already readable (`GET /accounts-payables`, `GET /accounts-receivables`) with no frontend built against them. **Payment Entry / Receipt Entry** is the natural next module — settling real, already-existing outstanding balances, not a new document type built from scratch.

Carried forward from this sprint specifically: the `ReceivingProgress`/`DeliveryProgress` pattern (badge + exact numbers, no bar) generalizes directly to a "Payment Progress" (paid / total) column on both list pages, and the Stock Ledger's cross-navigation pattern (`?filter=value` read via `useSearchParams`) is worth reusing for linking an AP/AR record to the Payment/Receipt Entries settled against it.
