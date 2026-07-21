# Frontend — Sales Workflow

Phase 2E. The mirror image of Purchase (`docs/FRONTEND_PURCHASE_WORKFLOW.md`) on the selling side: List + full-page Editor + Detail, no Drawer, same Transaction Document layout. Sales Order is the customer-facing counterpart to Purchase Order — it records a commitment, nothing more. **It never reduces inventory.** Stock only moves when a Delivery (the sales-side counterpart to Goods Receipt, not built this sprint) is submitted against it.

---

## 1. Folder Structure

New `features/sales/` module:

```
features/sales/
  types.ts                          SalesOrder, SalesOrderItem, form values, filter values,
                                     + a minimal Delivery/DeliveryItem shape (read-only lookup only)
  lib/
    salesOrderFilters.ts            emptySalesOrderFilters, hasActiveSalesOrderFilters
    salesOrderFormSchema.ts         zod schema — string-then-convert qty/rate, no stock check
    deliveryProgress.ts             computeDeliveryStatus/Totals — waiting/partial/completed
  api/
    salesOrderApi.ts                list (filtered), get, create, update, delete, submit, cancel
    deliveryApi.ts                  fetchDeliveries() only — minimal, see §4
  components/
    SalesOrderFiltersBar.tsx        status + date range, server-side (same shape as Purchase's)
    SalesOrderLineItemTable.tsx     editable field array — item lookup, add/remove, no stock check
    DeliveryProgress.tsx            StatusBadge + exact numbers, no progress bar (see §5)
  pages/
    SalesOrderListPage.tsx
    SalesOrderEditorPage.tsx
    SalesOrderDetailPage.tsx
```

Shared, reused rather than duplicated (see §3):

```
shared/lib/documentTotals.ts        moved out of features/purchase/lib/ this sprint —
                                     lineAmount/computeSubtotal/computeTax/computeGrandTotal
                                     are generic (qty × rate), now imported by both Purchase
                                     Order, Goods Receipt, and Sales Order
```

Backend (additive only — see §4):

```
app/Http/Requests/IndexSalesOrderRequest.php   new
app/Repositories/SalesOrderRepository.php      +search() method (paginate() untouched)
app/Services/SalesOrderService.php             list() now takes $filters
app/Http/Controllers/Api/V1/SalesOrderController.php   index() now injects IndexSalesOrderRequest
```

Routes: `/sales/orders` (list), `/sales/orders/new` (create), `/sales/orders/:id/edit` (edit, draft-only), `/sales/orders/:id` (detail). Sidebar's "Sales" nav item now points at `/sales/orders` instead of the placeholder `/sales`.

---

## 2. Sales Document Lifecycle

Same shared `DocumentStatus` enum as every other transactional document in this system (`draft` → `submitted` → `cancelled`), enforced by the same `Documentable` trait on the backend:

- **Draft**: fully editable (header + line items), deletable. Created via `POST /sales-orders`, edited via `PUT /sales-orders/{id}`.
- **Submitted**: `POST /sales-orders/{id}/submit` — requires at least one line item (`SalesOrderService::submit()` throws otherwise). This is a pure status transition — **no stock or ledger call happens here** (verified against the actual service code before writing any frontend logic; see §6).
- **Cancelled**: `POST /sales-orders/{id}/cancel` — blocked if any line already has `delivered_qty > 0` (mirrors Purchase Order's cancel-blocked-by-receipts rule exactly). Unlike Delivery, Sales Order **does** have a working cancel route, so the Editor/Detail/List all offer Cancel once submitted, same as Purchase Order.

---

## 3. Totals Calculation — Moved to Shared, Not Duplicated

Purchase Order's totals helper (`lineAmount`/`computeSubtotal`/`computeTax`/`computeGrandTotal`) was purely generic — `{ qty, rate }` in, a number out, no Purchase-specific logic — and Goods Receipt already had a byte-for-byte duplicate of it. Rather than add a third copy for Sales Order, both were moved to `shared/lib/documentTotals.ts` and all five existing importers (Purchase Order's Editor, Detail, and Line Item Table; Goods Receipt's Editor and Detail) were repointed at the shared file before any Sales code was written. Sales Order's Editor, Detail, and `SalesOrderLineItemTable` import from the same place.

`computeTax()` still returns a fixed `0` — no transactional document in this system has a tax field on the backend yet (`StoreSalesOrderRequest` doesn't accept one, same as Purchase Order and Goods Receipt). Grand Total therefore equals Subtotal today; the placeholder line in the Editor/Detail footer stays visible per the sprint's own instruction ("Tax (placeholder if backend does not support it)").

---

## 4. API Integration

| Action | Endpoint |
|---|---|
| List (filtered) | `GET /sales-orders?search=&status=&date_from=&date_to=&per_page=&page=` |
| Get one | `GET /sales-orders/{id}` |
| Create | `POST /sales-orders` |
| Update (draft only) | `PUT /sales-orders/{id}` |
| Delete (draft only) | `DELETE /sales-orders/{id}` |
| Submit | `POST /sales-orders/{id}/submit` |
| Cancel | `POST /sales-orders/{id}/cancel` |

Dropdown/lookup data: `GET /customers` (page 1, new `fetchCustomersLookup`), `GET /items` (page 1, existing `fetchItemsLookup`, shared with Purchase).

**Backend change made this sprint (pre-authorized in the sprint brief, additive-only):** `SalesOrderController::index()` had the identical no-filtering gap Purchase Order had before Phase 2C — `list()` took only an `int $perPage`, no query parameters were read at all. Added `IndexSalesOrderRequest` (search, status, date_from, date_to, per_page — byte-for-byte the same rules as `IndexPurchaseOrderRequest`), `SalesOrderRepository::search()` mirroring `PurchaseOrderRepository::search()`'s `when()`-chain exactly (search matches `document_number` or the customer's `customer_name`), and threaded filters through `SalesOrderService::list(array $filters, int $perPage)`. Verified directly against the running API via curl — status filter, search + date range, and an invalid status value returning a 422 — before any frontend code was written. No business logic, schema, or lifecycle code touched.

**Deliberately not touched:** Delivery's `index()` has the same gap (`DeliveryService::list()` still takes only `int $perPage`), but adding `IndexDeliveryRequest` was not requested or pre-authorized this sprint — Delivery is next module's work (see §7). The Sales Order Detail page's "related deliveries" lookup (`deliveryApi.ts`) calls the existing unfiltered `GET /deliveries` as-is and filters client-side by `sales_order_id`, with a documented ceiling: results are limited to whatever the default `per_page=15` returns, since there's currently no way to request more. This mirrors the same "page-1-only lookup" ceiling already accepted elsewhere in this codebase (Warehouse→Branch, and originally Goods Receipt→Purchase Order before Phase 2D added `per_page` support there).

---

## 5. Delivery Progress Calculation

Sales Order items already track `qty` (ordered), `delivered_qty`, and `outstanding_qty = qty - delivered_qty` — computed server-side (`SalesOrderItemResource`), not derived on the frontend. `is_fully_delivered` is likewise computed server-side (`SalesOrderResource`, `every(delivered_qty >= qty)` over loaded items).

`features/sales/lib/deliveryProgress.ts` derives a **display-only** status from that data, mirroring `features/purchase/lib/receivingProgress.ts` exactly:

- **Waiting**: submitted, `delivered === 0`.
- **Partial**: submitted, `0 < delivered`, not fully delivered.
- **Completed**: submitted, `is_fully_delivered === true`.
- **—** (no badge): draft or cancelled — delivery is structurally impossible for either (a draft SO has nothing to deliver against yet; cancellation is blocked once any line has `delivered_qty > 0`, so a cancelled SO is guaranteed to have zero deliveries).

**No progress bar** — this sprint's instruction was explicit ("Do not use progress bars. Use: Status Badge + Exact Numbers"), and it's also the exact simplification just applied to Purchase Order's `ReceivingProgress` in the prior sprint (Phase 2C.1). `DeliveryProgress` was built directly in that already-simplified shape rather than needing a follow-up trim: `<StatusBadge status={status} /> delivered / ordered`, one row, same height in a table cell as any other column.

---

## 6. Architectural Rule — Sales Order Never Touches Stock

Verified against the actual backend code before writing any frontend logic, not assumed:

- `SalesOrderService` imports and injects only `SalesOrderRepository` and `SalesOrderItemRepository`. No `StockLedgerService` reference anywhere in the file. `submit()` is exactly: validate at least one item exists, call `$salesOrder->submit()` (a pure status flip via the `Documentable` trait), return. No ledger call, no `delivered_qty` mutation.
- `DeliveryService`, by contrast, injects `StockLedgerService` **and** `AccountsReceivableService`. Its `submit()` is where `StockTransactionType::OUT` is actually recorded per line, `SalesOrderItem.delivered_qty` is incremented, and `AccountsReceivableService::createFromDelivery()` runs — the sales-side mirror of `AccountsPayableService::createFromGoodsReceipt()`.

The frontend respects this boundary structurally, not just by convention: `SalesOrderLineItemTable` has no stock-awareness of any kind — no warehouse field (Sales Order has no `warehouse_id` column; a warehouse is only chosen at Delivery time), no available-quantity check, no validation that would ever block a qty because of current stock. A Sales Order can legitimately list a quantity that exceeds what's in the warehouse — that's intentional per the sprint brief, and there is no code path in this module that could prevent it even accidentally.

---

## 7. Relationship to Delivery

Delivery is **not built this sprint** — only what Sales Order's Detail page needs to point at it:

- `Delivery` has `sales_order_id` but `DeliveryResource` does not nest a `sales_order` object (confirmed by reading the resource directly) — the same "no nested parent doc" convention as `GoodsReceiptResource` not nesting `purchase_order`. The Sales Order Detail page's "Deliveries" card resolves the relationship from the Sales Order's own side instead: fetch `GET /deliveries`, filter client-side to `delivery.sales_order_id === order.id`, same lookup-join pattern used for Goods Receipt → Purchase Order in Phase 2C/2D.
- The card's Document Number column is **plain text, not a link** — unlike Purchase Order's equivalent "Goods Receipts" card (which links to a real `GoodsReceiptDetailPage`), there is no `/sales/deliveries/:id` route yet. Linking to a route that doesn't exist would silently fall through the router's catch-all back to `/dashboard`; showing plain text until the Delivery module actually exists is the honest choice.
- Only fetched when `order.status === 'submitted'` — a draft Sales Order is guaranteed to have zero deliveries (nothing to deliver against yet), so there's no reason to issue the request.

---

## 8. Reusable Components

**Reused as-is:** `PageHeader`, `ActionBar`, `SearchBox`, `FilterPanel`, `DataTable`, `RowActionsMenu`, `Pagination`, `StatusBadge`, `DeleteDialog`, `DetailField`/`DetailSection`, `Card`/`Textarea`/`Select`/`Form` — the entire document-editor shell established in Phase 2C, unchanged.

**Reused from Purchase, generalized this sprint:** `shared/lib/documentTotals.ts` (see §3).

**New, entity-specific (following the established per-entity pattern rather than forcing a shared abstraction):** `SalesOrderFiltersBar`, `SalesOrderLineItemTable` (structurally near-identical to `PurchaseOrderLineItemTable` — same field-array/add-row/item-lookup-autofill shape, since Sales Order's line items behave exactly like Purchase Order's, unlike Goods Receipt's fixed-row table), `DeliveryProgress` (structurally near-identical to `ReceivingProgress`, minus the bar it never had reason to include).

No `SectionNav` was added to the Sales module — that component is added only once a second sibling document type exists in the same section (the rule already established in Phase 2D for Purchase). Sales currently has exactly one document type (Sales Order); it'll gain a `salesSectionNav` when Delivery is built.

---

## 9. Design Decisions & Trade-offs

- **No stock validation anywhere in this module, on purpose.** Explicitly required by the sprint brief and verified against the backend (§6) rather than assumed from the UI spec alone.
- **Delivery Progress was built already-simplified**, not simplified after the fact — this sprint's explicit "no progress bar" instruction is the same conclusion the prior sprint (2C.1) reached for Purchase Order's `ReceivingProgress` after shipping it with a bar first. `DeliveryProgress` skipped that iteration.
- **`documentTotals.ts` moved to `shared/lib/`, not copied a third time.** Two identical copies (Purchase Order's, Goods Receipt's) was already one too many; a third copy for Sales Order would have meant three places to keep in sync if a tax field is ever added. The move is a pure relocation — no behavior changed, five existing call sites repointed alongside the new Sales Order ones.
- **Delivery gets a data type and a single read-only fetch function, not a stub module.** Building `deliveryApi.ts` with `create`/`update`/`submit` functions this sprint, with no Editor to call them from, would be dead code. `fetchDeliveries()` exists because the Sales Order Detail page genuinely needs it today; everything else about Delivery waits for its own sprint.
- **Cancel is present for Sales Order, absent for the Delivery lookup — intentionally asymmetric**, because the backend itself is asymmetric here: `SalesOrder::cancel()` works (blocked only by existing deliveries), `Delivery::cancel()` unconditionally throws. The frontend was built to match each backend's real capability, not a single templated lifecycle applied to both.

---

## 10. Screenshots

- Sales Order List (2 orders — SO-00001 fully delivered, SO-00002 waiting — Delivery Progress column): `screenshot-1784272300207-31.jpg`
- Sales Order Editor (new, empty state): `screenshot-1784272315539-32.jpg`
- Sales Order Detail (submitted SO-00002 — Delivery Progress card, line items with Delivered/Remaining Qty, empty Deliveries card): `screenshot-1784272277668-30.jpg`

Verified end-to-end in the browser beyond the screenshots: created a draft Sales Order (Customer Regression, one line item, item lookup correctly autofilled Unit Price from `standard_rate`), saved as draft (`SO-00002` assigned), submitted it, confirmed the Detail page immediately reflected `Waiting 0 / 1` and a working Cancel action.

---

## 11. Recommendation for the Next Module

**Delivery Workflow** — the natural and only sensible next step, for the same reason Goods Receipt followed Purchase Order: two real Sales Orders now exist with genuine outstanding quantity (`SO-00001` fully delivered already from prior seed data, `SO-00002` freshly created this sprint sitting at `0 / 1`), so there's real data waiting the moment Delivery exists, exactly as this sprint found waiting Purchase Order data when Goods Receipt was built.

Carried forward directly from Goods Receipt's own experience (`docs/FRONTEND_GOODS_RECEIPT_WORKFLOW.md` §6): Delivery should originate **only** from an existing Sales Order, with fixed, non-addable line rows populated from the SO's own outstanding quantities — not a free-form line-item table. `SalesOrderLineItemTable` (this sprint) is the wrong template to reuse for Delivery's line items; `GoodsReceiptLineItemTable` (Phase 2D) is the right one.

Also due at that point: `IndexDeliveryRequest` (deliberately skipped this sprint, see §4) and a `salesSectionNav` so Sales Orders and Deliveries become navigable siblings, mirroring Purchase's `purchaseSectionNav` exactly.
