# Frontend — Delivery Workflow

Phase 2F. The mirror image of Goods Receipt (`docs/FRONTEND_GOODS_RECEIPT_WORKFLOW.md`) on the selling side: List + full-page Editor + Detail, no Drawer, same Transaction Document layout, same fixed-row-from-parent-document editor shape. Delivery is where inventory physically leaves the warehouse — the operational counterpart to Sales Order's pure paperwork commitment.

---

## 1. Folder Structure

Added to the existing `features/sales/` module (Delivery is conceptually part of Sales, mirroring how Goods Receipt lives inside `features/purchase/`):

```
features/sales/
  navigation.ts                       new — salesSectionNav, now used by both SO and Delivery list pages
  types.ts                            +DeliveryFormValues, DeliveryFilterValues (Delivery/DeliveryItem already existed, minimal, from Phase 2E)
  lib/
    deliveryFilters.ts                emptyDeliveryFilters, hasActiveDeliveryFilters
    deliveryFormSchema.ts             zod schema — fixed-row line items, dual-bound validation (remaining AND stock)
  api/
    deliveryApi.ts                    expanded from Phase 2E's read-only stub to full CRUD: list (filtered), get, create, update, delete, submit — no cancel
  components/
    DeliveryFiltersBar.tsx            status + date range, server-side (same shape as Sales Order's)
    DeliveryLineItemTable.tsx         fixed rows from the selected SO, Available Stock column, Deliver Now only editable field
  pages/
    DeliveryListPage.tsx
    DeliveryEditorPage.tsx            two-phase: pick a Sales Order, then the document form
    DeliveryDetailPage.tsx
```

New `features/inventory/` module (first real file in what was an empty placeholder):

```
features/inventory/
  api/
    stockApi.ts                       fetchStockBalances() — bulk, warehouse-scoped current stock
```

Backend (additive only — see §3):

```
app/Http/Requests/IndexDeliveryRequest.php        new
app/Repositories/DeliveryRepository.php           +search() method (paginate() untouched)
app/Services/DeliveryService.php                  list() now takes $filters
app/Http/Controllers/Api/V1/DeliveryController.php   index() now injects IndexDeliveryRequest
app/Http/Requests/IndexStockBalanceRequest.php    new
app/Http/Controllers/Api/V1/StockLedgerController.php  +balances() action
routes/api.php                                    +GET stock-ledger/balances
```

Routes: `/sales/deliveries` (list), `/sales/deliveries/new` (create — starts with Sales Order selection), `/sales/deliveries/:id/edit` (edit, draft-only), `/sales/deliveries/:id` (detail).

---

## 2. Workflow

```
Sales Order (submitted, outstanding items)
        ↓
Select Sales Order
        ↓
Load outstanding quantities (Ordered / Already Delivered / Remaining, from the SO itself)
        ↓
Select a Warehouse → load Available Stock (from the new bulk balance endpoint, scoped to that warehouse)
        ↓
Select Deliver Now quantities (bounded by both Remaining and Available Stock)
        ↓
Record Delivery (draft — captures what's about to leave, moves nothing yet)
        ↓
Confirm Delivery (submit — stock reduced, SalesOrderItem.delivered_qty incremented, Accounts Receivable created)
```

No manual line creation anywhere in this flow — `DeliveryLineItemTable` has no Add Row control, matching `GoodsReceiptLineItemTable`'s structural (not just validated) guarantee that a line can never reference an item outside the source Sales Order.

---

## 3. API Integration

| Action | Endpoint |
|---|---|
| List (filtered) | `GET /deliveries?search=&status=&date_from=&date_to=&per_page=&page=` |
| Get one | `GET /deliveries/{id}` |
| Create | `POST /deliveries` |
| Update (draft only) | `PUT /deliveries/{id}` |
| Delete (draft only) | `DELETE /deliveries/{id}` |
| Submit | `POST /deliveries/{id}/submit` |
| Bulk stock balance | `GET /stock-ledger/balances?warehouse_id=&item_ids[]=&item_ids[]=...` |

**No cancel endpoint** — `Delivery::cancel()` unconditionally throws ("reversal is only available through the Return workflow, not yet implemented"), same as Goods Receipt. `deliveryApi.ts` has no `cancelDelivery` function; no Cancel button appears anywhere for Delivery, for any status.

Dropdown/lookup data: `GET /sales-orders?status=submitted&per_page=100` (candidate SOs, filtered client-side to `!is_fully_delivered`), `GET /warehouses` (page 1, existing `fetchWarehousesLookup`).

**Backend change #1 (pre-authorized in the sprint brief, additive-only): `IndexDeliveryRequest`.** `DeliveryController::index()` had the identical no-filtering gap Sales Order had before Phase 2E — `list()` took only `int $perPage`. Added `IndexDeliveryRequest` (search/status/date_from/date_to/per_page, byte-for-byte matching `IndexGoodsReceiptRequest`'s rules), `DeliveryRepository::search()` (search matches `document_number` or the customer's `customer_name`, dates bound `delivery_date`), and threaded filters through `DeliveryService::list(array $filters, int $perPage)`. Verified via curl (status filter, invalid-status 422) before frontend work began.

**Backend change #2 (explicitly approved by the user this sprint, additive-only): bulk stock balance endpoint.** This one was *not* pre-authorized in the brief — it surfaced during planning, when it became clear the "Available Stock" column had no existing API to read from (see §5). Presented three options (expose the existing calculation via a new endpoint; use the wrong global `Item.current_stock` number; skip client-side stock validation entirely) and the user chose the first, explicitly requesting a bulk shape over one-call-per-item. Implementation: `IndexStockBalanceRequest` (`warehouse_id` uuid, `item_ids` array of uuids), `StockLedgerController::balances()` — a thin loop over `StockLedgerService::getCurrentBalance()`, which already existed and was already the single source of truth `DeliveryService::assertSufficientStock()` uses at submit time. No business logic changes, no schema changes; the endpoint exposes an existing, already-correct calculation. Verified via curl (valid request, missing `warehouse_id` → 422, malformed uuid → 422) before frontend work began.

---

## 4. Outstanding Quantity Logic

Identical mechanism to Goods Receipt, on the Sales Order side: `SalesOrderItem` already tracks `qty` (ordered), `delivered_qty`, and `outstanding_qty = qty - delivered_qty`, computed server-side (`SalesOrderItemResource`). The Editor only displays this snapshot and bounds input against it:

1. When a Sales Order is selected (create mode) or already known (edit mode), the frontend fetches that SO **fresh** via `fetchSalesOrder(id)` — never reused from the eligible-orders list cache — so `outstanding_qty` reflects the true current state.
2. Each SO line becomes one fixed row: `ordered = soItem.qty`, `alreadyDelivered = soItem.delivered_qty`, `remaining = soItem.outstanding_qty`.
3. `delivered_qty` only increments on **submit** (`SalesOrderItemRepository::incrementDeliveredQty()`, called from `DeliveryService::submit()`), never on a draft save — so a freshly-fetched SO's `outstanding_qty` is always the correct ceiling, same reasoning as Goods Receipt §2.

---

## 5. Stock Validation — the New Part

Purchase → Goods Receipt never needed this: receiving *adds* stock, so there's no ceiling to check beyond the PO's outstanding quantity. Delivery *removes* stock, so a second, independent ceiling exists — physical quantity on hand — and the sprint required it be enforced client-side, not just discovered as a submit-time error.

**Where "available stock" comes from:** `StockLedgerService::getCurrentBalance(itemId, warehouseId)` — the same method `DeliveryService::assertSufficientStock()` already calls internally at submit time. Before this sprint, nothing exposed it over HTTP. The new bulk endpoint (§3) is a direct pass-through, so the number the Editor shows is provably the same number the backend will enforce — no risk of the UI showing a friendlier number than what actually gets validated.

**Why warehouse-scoped, not `Item.current_stock`:** `Item.current_stock` is a global cache summed across every warehouse (confirmed by reading `StockLedgerRepository::totalBalanceForItem()` before writing any frontend code). A Delivery ships from exactly one warehouse (`Delivery.warehouse_id`, header-level, not per-line). Using the global number would have let the Editor accept a quantity that's in stock *somewhere* but not in the warehouse actually being shipped from — client-side validation that lies. This was flagged to the user explicitly before implementation (see §3, backend change #2) rather than assumed.

**Two independent ceilings, two distinct messages.** `deliveryLineRowSchema`'s `superRefine` checks `deliverNow > remaining` first (reports "Cannot exceed remaining order quantity (N)"), then `deliverNow > availableStock` (reports "Cannot exceed available stock (N)") — whichever binds is named specifically, not folded into one generic "too much" error. The line-item table additionally renders Available Stock in `text-destructive` when it's the tighter of the two ceilings, *before* the user types anything — visible cause, not just a rejected value after the fact.

**Timing: stock is only knowable after a warehouse is chosen.** Unlike Goods Receipt (where every field the table needs comes from the PO at selection time), Delivery's line rows start with `availableStock: 0` and are patched in once `warehouse_id` changes and the bulk balance query resolves — implemented as two separate `useEffect`s in `DeliveryEditorPage` deliberately kept apart: one establishes the row set from the Sales Order (fires once per SO/delivery load), the other patches in `availableStock` per row (fires whenever the warehouse selection changes) — so switching warehouses never wipes Deliver Now quantities the user already typed.

---

## 6. Partial Delivery Behavior

True by construction, same as Goods Receipt's partial-receipt behavior:

- A Delivery can include any subset of a Sales Order's lines, at any quantity from `0` up to `min(remaining, availableStock)`. Lines left at `0` are excluded from the API payload.
- After a partial delivery is submitted, the Sales Order stays `submitted` and remains selectable for a **subsequent** Delivery — verified end-to-end this sprint: SO-00002 (1 unit ordered) was delivered in full via one Delivery and its `is_fully_delivered` flipped `true`, dropping it out of the eligible-orders list; a second live-data Sales Order (SO-00003) with its own outstanding quantity remained selectable for the next Delivery.
- The Editor's "Select Sales Order" step filters to `status === 'submitted' && !is_fully_delivered`, computed server-side (`SalesOrderResource.is_fully_delivered`), not re-derived on the frontend — identical pattern to Goods Receipt's PO-picker filter.

---

## 7. Reusable Components

**Reused as-is:** `PageHeader`, `ActionBar`, `SearchBox`, `FilterPanel`, `DataTable`, `RowActionsMenu`, `Pagination`, `StatusBadge`, `DeleteDialog`, `DetailField`/`DetailSection`, `Card`/`Textarea`/`Select`/`Form`, `SectionNav` — the entire document-editor shell, unchanged.

**Reused from Purchase/Sales:** `shared/lib/documentTotals.ts` (tax-is-zero placeholder, same as every other transactional document).

**New, entity-specific:** `DeliveryFiltersBar` (near-identical to `SalesOrderFiltersBar`/`GoodsReceiptFiltersBar`), `DeliveryLineItemTable` (structurally close to `GoodsReceiptLineItemTable` — fixed rows, no Add Row, no item lookup — but with the added Available Stock column and dual-bound cap that Goods Receipt never needed).

**New, cross-feature:** `features/inventory/api/stockApi.ts` — deliberately placed outside `features/sales/`, since the underlying capability (warehouse-scoped current stock) is an Inventory concern being *consumed* by Delivery, not owned by it. Documented in the endpoint's own comment as reusable by "any future editor that needs to preview available stock before submitting" — e.g., a future Stock Adjustment module.

---

## 8. Design Decisions & Trade-offs

- **Asked before adding the stock-balance endpoint, even though `IndexDeliveryRequest` was pre-authorized.** The sprint brief only pre-authorized the filtering request (the same additive pattern used three times already this project). A new read endpoint exposing a previously-internal calculation is a different kind of change, and the "Available Stock" requirement couldn't be satisfied correctly without one — so this was surfaced explicitly via `AskUserQuestion` rather than assumed, consistent with this project's standing discipline (never touch backend without either per-sprint pre-authorization or direct approval).
- **Bulk over per-item**, per explicit user instruction: the Editor needs the balance for every line at once when a warehouse is chosen, and other future editors "will benefit from the same endpoint later" — a single `GET .../balances?item_ids[]=...` avoids N round-trips and gives the bulk shape room to be reused as-is.
- **`stockApi.ts` lives in a new `features/inventory/`, not inside `features/sales/`.** Stock balance is not a Sales concept — it's Inventory data Delivery happens to need. Placing it under Sales would have made the next consumer (a real Inventory module, or Stock Adjustment) import cross-feature from Sales, backwards from the actual dependency direction.
- **Two separate `useEffect`s in the Editor, not one.** A single effect keyed on both the Sales Order and the stock balances would have to choose between wiping Deliver Now on every warehouse change (bad UX — retyping quantities after fixing a warehouse mistake) or complex merge logic to preserve them inline. Splitting "establish rows" from "patch in stock" cleanly separates two different data sources with two different lifetimes at the cost of one extra `useEffect` — the ponytail-correct trade here, since the merge-logic alternative is strictly more code for a worse result.
- **Consistent renamed-language convention extended to Delivery.** Following Goods Receipt's Phase 2C.1 refinement (`Save Draft`/`Submit` → `Receive Goods`/`Confirm Receipt`), Delivery's buttons read `Record Delivery`/`Confirm Delivery` from day one rather than shipping generic language and renaming it in a later sprint. Toasts describe effects ("stock updated", "creates the receivable"), not ERP mechanics.
- **A real bug this sprint exposed and fixed: `useFieldArray`'s `fields` snapshot isn't reactive to plain `form.setValue()` calls.** `DeliveryLineItemTable` initially read `availableStock` straight from the `fields` array (same as `GoodsReceiptLineItemTable` reads its static `ordered`/`alreadyReceived`/`remaining`), which is correct for values that are fixed at row-creation time — but `availableStock` changes *after* creation, once the async balance lookup resolves, and `setValue` on a field not wrapped in a `Controller`/`FormField` doesn't propagate into `useFieldArray`'s own snapshot. Fixed by reading `availableStock` through `useWatch({ control, name: 'items' })` instead, which does subscribe to live updates. Caught during browser verification (Available Stock stubbornly showed `0` despite the network call succeeding and returning `107`) rather than assumed correct from code review alone — a reminder that this class of RHF/`useFieldArray` staleness bug won't show up in `tsc`/lint, only in actually exercising the flow.

---

## 9. Screenshots

- Delivery List (SectionNav Orders/Deliveries, 2 deliveries — DN-00001 fully-delivered SO-00001, DN-00002 fresh): `screenshot-1784273778053-33.jpg`
- Delivery Editor (SO-00002 selected, Main Warehouse chosen, Available Stock resolved to 107, Deliver Now enabled): `screenshot-1784275420896-34.jpg`
- Delivery Detail (DN-00002, confirmed/submitted, View Sales Order link, delivered items, totals, audit info): `screenshot-1784275720502-35.jpg`
- Delivery List after confirmation (Qty Delivered columns correct for both rows): `screenshot-1784275847581-36.jpg`

Verified end-to-end in the browser beyond the screenshots: created a draft Delivery against SO-00002 (Main Warehouse, Deliver Now = 1), confirmed via direct API check that `Item.current_stock` dropped `107 → 106` and `SalesOrderItem.delivered_qty` incremented to `1` with `is_fully_delivered` flipping to `true` only after submit (not after the draft save) — confirming Delivery, not Sales Order, is what moves stock, and that a draft Delivery has zero effect on it.

---

## 10. Recommendation for the Next Module

**Payment Entry** — the standing recommendation from Phase 2D still holds, and now applies on both sides of the business: every Goods Receipt submission creates a real, unpaid Accounts Payable record, and as of this sprint, every Delivery submission creates a real, unpaid Accounts Receivable record (`AccountsReceivableService::createFromDelivery()`, verified structurally identical to `AccountsPayableService::createFromGoodsReceipt()` during backend investigation). Payment Entry can settle against either side using the same "select outstanding records" pattern.

One thing worth carrying forward specifically from this sprint: **when a new module needs data the backend computes but doesn't yet expose, ask before adding the endpoint** — even when a similar-looking change (`Index*Request` filtering) was already pre-authorized elsewhere in the same brief. The two are different classes of change, and this sprint's stock-balance endpoint is a clean template for the next time it comes up: identify the existing, already-correct calculation; expose it as a thin, additive, read-only pass-through; verify via curl before any frontend code depends on it.
