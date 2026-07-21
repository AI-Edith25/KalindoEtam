# Frontend — Reports Module

Sprint 8. Read-only from end to end — no create, update, delete, submit, or approval anywhere in this module. Every report is a filtered, paginated *view* over data that Purchase, Sales, and Inventory already own and already expose; nothing here introduces a new domain concept, a new document type, or new business logic. The sprint's own framing drove every decision below: reuse existing APIs, only extend them additively where a genuinely missing filter or column made that unavoidable, and never move business rules into a report controller.

---

## 1. Architecture

```
features/reports/
  navigation.ts        reportsSectionNav — Purchase / Goods Receipt / Sales / Delivery / Inventory Movement / Inventory Balance
  types.ts              filter value types for the 4 reports that needed a new shape (see §2)
  lib/
    reportFilters.ts    empty/hasActive helpers for those same 4 filter types
  components/
    PurchaseReportFiltersBar.tsx, GoodsReceiptReportFiltersBar.tsx,
    SalesReportFiltersBar.tsx, DeliveryReportFiltersBar.tsx
  pages/
    PurchaseReportPage.tsx, GoodsReceiptReportPage.tsx, SalesReportPage.tsx,
    DeliveryReportPage.tsx, InventoryMovementReportPage.tsx, InventoryBalanceReportPage.tsx
```

Every page follows the same shell as every other list page in this app: `SectionNav` (the 6 report tabs) → `PageHeader` (title, live count, Refresh/Export/Import — Export/Import disabled, same "always shown, disabled when unimplemented" convention as every transactional list) → optional `SummaryCard` row → `SearchBox` + a `FilterPanel`-based filters bar → `DataTable` → `Pagination`. No `RowActionsMenu`, no `DeleteDialog`, no primary "New" button anywhere — the entire module is read-only, so those pieces of the standard shell simply don't apply here.

Routes (`app/router.tsx`): `/reports/purchase`, `/reports/goods-receipts`, `/reports/sales`, `/reports/deliveries`, `/reports/inventory-movement`, `/reports/inventory-balance`. The sidebar's "Reports" link, previously a dead placeholder (`/reports`, falling through the router's catch-all to `/dashboard`), now points at `/reports/purchase` — mirroring how Inventory lands on `/inventory/stock-balance`.

---

## 2. Reuse vs. new — report by report

Four of the six reports reuse an existing document API and existing document types outright (`PurchaseOrder`, `GoodsReceipt`, `SalesOrder`, `Delivery` — all from Purchase/Sales' own `types.ts`, imported directly rather than duplicated a third time). The other two reuse Inventory's own list pages' API and filter bar **unchanged**.

| Report | API reused | New backend filter | New FiltersBar |
|---|---|---|---|
| Purchase | `fetchPurchaseOrders()` | `supplier_id` | `PurchaseReportFiltersBar` |
| Goods Receipt | `fetchGoodsReceipts()` | `warehouse_id` | `GoodsReceiptReportFiltersBar` |
| Sales | `fetchSalesOrders()` | `customer_id` | `SalesReportFiltersBar` |
| Delivery | `fetchDeliveries()` | `warehouse_id` | `DeliveryReportFiltersBar` |
| Inventory Movement | `fetchStockLedgerEntries()` | `voucher_type` | none — reuses `StockLedgerFiltersBar` as-is |
| Inventory Balance | `fetchStockBalanceReport()` | `item_id`, `uom` (see §3) | none — reuses `StockBalanceFiltersBar` as-is |

None of the four document list endpoints exposed a supplier/customer/warehouse filter before this sprint (each only filtered by status and date range) — the report spec explicitly calls for one, so each `Index*Request`/`Repository::search()` pair got one additive `when()` clause, exactly mirroring the shape every prior filter in this codebase already uses (`AccountsPayableRepository::search()`'s pattern). No new tables, no new controllers, no business logic anywhere in these six changes — curl-verified individually before any frontend code was written against them, per this project's established discipline.

The two Inventory reports needed **zero** new FiltersBar components — Stock Ledger's List page and Stock Balance's List page already had exactly the filters this sprint's spec asks for (Date Range/Item/Warehouse/Voucher Type, and Item/Warehouse respectively), so the report pages import and reuse `StockLedgerFiltersBar`/`StockBalanceFiltersBar` directly rather than building near-duplicates.

---

## 3. The one field that didn't exist yet: UOM

The Inventory Balance Report spec requires a UOM column, but `StockBalanceResource` (built in Phase 2G for Inventory's own Stock Balance list) never included one — that list only ever showed Item/Warehouse/Current Qty/Reserved Qty/Available Qty/Reorder Level. Rather than fabricate it client-side or build a second lookup-join against `/items`, `StockLedgerRepository::currentBalances()` gained one additional join (`uoms` via `items.uom_id`) and one additional selected column (`uoms.name as uom`), and `StockBalanceResource` now exposes it. This is the same shape of change as the 6 filter additions above — additive, no business logic, curl-verified (`"uom":"Pcs"` confirmed in the response) — and it improves Inventory's own existing Stock Balance list for free, since `StockBalanceRow` is shared between both.

---

## 4. Summary cards

Built only where the sprint's own examples called for one — Goods Receipt and Delivery reports have no summary cards, matching the spec (only Purchase, Sales, and Inventory Balance were given examples).

- **Purchase Report**: Total Purchases (`meta.total`, always accurate — it's a count), Total Amount (sum of `total_amount` across every row).
- **Sales Report**: Total Sales (`meta.total`), Total Revenue (sum of `total_amount`).
- **Inventory Balance Report**: Total Items (`meta.total`), Total Stock Quantity (sum of `current_qty`).

All three reuse `dashboard/components/SummaryCard.tsx` completely unchanged — no report-specific card component was built.

**Sum-type cards are computed from the currently-loaded page only**, not a backend aggregate — `// ponytail:` marked in each page. Count-type cards (Total Purchases, Total Sales, Total Items) use `meta.total` and are therefore always accurate regardless of pagination; sum-type cards (Total Amount, Total Revenue, Total Stock Quantity) are a known ceiling that matches this app's existing page-1-only lookup convention (`shared/services/lookupApi.ts`). Every report's dataset currently fits on one page in practice, so this is a real simplification, not a hidden bug — the upgrade path, if a report's dataset ever outgrows one page, is a small backend aggregate endpoint (`SUM()`/`COUNT()` alongside the existing paginated query), not a redesign.

---

## 5. Cross-module navigation, reused verbatim

- **Goods Receipt Report's "Purchase No"** and **Delivery Report's "Sales Order"** columns use the exact client-side lookup-join pattern already established in `GoodsReceiptListPage`/`DeliveryListPage` (`GoodsReceiptResource`/`DeliveryResource` don't nest their parent document, so a `per_page: 100` lookup query resolves the id to a document number and links to that document's own Detail page).
- **Inventory Movement Report's "Voucher No"** column reuses `resolveVoucherLink()` unchanged from Inventory's own Stock Ledger list — same clickable-link-or-plain-text behavior, same `stock_in`-has-no-page-yet handling.
- **Row click** on every report navigates straight to the underlying document's existing Detail page (`/purchase/orders/:id`, `/purchase/goods-receipts/:id`, `/sales/orders/:id`, `/sales/deliveries/:id`) — Inventory Balance Report's row click reuses Stock Balance List's own cross-navigation into Stock Ledger (`?item_id=&warehouse_id=`). No new Detail page was built for any report; a report row's "detail" is always the real document it's reporting on.

---

## 6. Design decisions

- **No dedicated report-level Detail page, anywhere.** Every report row already links to a real, existing Detail page for the underlying document — building a second "report detail" view would just re-show the same fields a click away.
- **Inventory's two reports get no new FiltersBar** — extending the existing `StockLedgerFiltersBar`/`StockBalanceFiltersBar` would have meant either forking them or overloading them with report-specific-only fields that don't exist; since their filter shape already matched the spec exactly, reusing them outright was the correct amount of code, not a shortcut.
- **The 4 document reports get their own new FiltersBar rather than extending Purchase/Sales/Delivery/Goods Receipt's own list-page FiltersBar.** Those existing bars intentionally only expose Status + date range — adding a supplier/customer/warehouse dropdown to them would change the filtering UI of four already-shipped, unrelated list pages for the sake of a new read-only module. A second, report-scoped bar per document type keeps the blast radius to exactly this sprint.
- **`DocumentStatus` is imported from Purchase's/Sales' own `types.ts`, not duplicated a third time.** Every other feature in this app duplicates `DocumentStatus` locally (Purchase, Sales, Inventory each have their own copy) because each of those features owns its own document lifecycle. Reports doesn't own any lifecycle — it only *displays* Purchase's and Sales' — so importing their type directly is reuse, not an exception to the duplication convention.

---

## 7. Deliverables

**1. Pages completed** (all read-only, Export/Import disabled, no create/edit/delete/submit):
`PurchaseReportPage`, `GoodsReceiptReportPage`, `SalesReportPage`, `DeliveryReportPage`, `InventoryMovementReportPage`, `InventoryBalanceReportPage`.

**2. Components created or reused:**
- New: `PurchaseReportFiltersBar`, `GoodsReceiptReportFiltersBar`, `SalesReportFiltersBar`, `DeliveryReportFiltersBar`.
- Reused unchanged: `StockLedgerFiltersBar`, `StockBalanceFiltersBar`, `SummaryCard`, `PageHeader`, `ActionBar`, `SearchBox`, `FilterPanel`, `DataTable`, `Pagination`, `StatusBadge`, `SectionNav`, `resolveVoucherLink`.

**3. APIs reused or added:**
- Reused unchanged: `fetchPurchaseOrders`, `fetchGoodsReceipts`, `fetchSalesOrders`, `fetchDeliveries`, `fetchStockLedgerEntries`, `fetchStockBalanceReport`.
- Backend additions (all additive, all curl-verified): `supplier_id` filter on Purchase Order index, `customer_id` on Sales Order index, `warehouse_id` on Goods Receipt index, `warehouse_id` on Delivery index, `voucher_type` on Stock Ledger index, `item_id` on Stock Balance report, and the `uom` field on `StockBalanceResource` (§3). Zero new tables, controllers, or business logic.

**4. Screenshots** (all captured with real seeded data, filters and row-click navigation verified interactively):
- Purchase Report — 7 orders, Total Purchases/Total Amount cards, Supplier + Status filters: `screenshot-1784303125458-64.jpg`
- Goods Receipt Report — 6 receipts, Purchase No resolved via lookup-join, Warehouse filter: `screenshot-1784303148833-68.jpg`
- Sales Report — 5 orders, Total Sales/Total Revenue cards, Customer + Status filters: `screenshot-1784303058886-61.jpg`
- Delivery Report — 4 deliveries, Sales Order resolved via lookup-join, Warehouse filter: `screenshot-1784303148825-67.jpg`
- Inventory Movement Report — 14 entries, Voucher Type badges, Voucher No linked, Qty In/Out, Running Balance: `screenshot-1784303125450-63.jpg`
- Inventory Balance Report — 2 rows, Total Items/Total Stock Quantity cards, UOM column: `screenshot-1784303031983-58.jpg`

Also verified: row click on Purchase Report navigates to the real Purchase Order Detail page (`PO-00001`); `npx tsc -b` and `npx oxlint` both clean (no new warnings or errors from any Reports file).

**5. Design decisions made during implementation:** see §3, §4, and §6 above.
