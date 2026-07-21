# Frontend — Goods Receipt Workflow

Phase 2D. The bridge between Purchase and Inventory, built on the same Transaction Document layout as Purchase Order (`docs/FRONTEND_PURCHASE_WORKFLOW.md`) — List + full-page Editor + Detail, no Drawer. Purchase's own pages were not redesigned; the only change made to them was adding `SectionNav` so the two sibling document types (Orders, Goods Receipts) can be navigated between.

---

## 1. Folder Structure

Added to the existing `features/purchase/` module (Goods Receipt is conceptually part of Purchase, per `.ia/03_ERP_BLUEPRINT.md` and the backend's own route comments):

```
features/purchase/
  navigation.ts                       purchaseSectionNav — now used by both PO and GR list pages
  types.ts                            +GoodsReceipt, GoodsReceiptItem, form values, filter values
  lib/
    goodsReceiptFilters.ts            emptyGoodsReceiptFilters, hasActiveGoodsReceiptFilters
    goodsReceiptFormSchema.ts         zod schema — fixed-row line items, no add/remove
    goodsReceiptTotals.ts             lineAmount, computeSubtotal, computeTax, computeGrandTotal
  api/
    goodsReceiptApi.ts                list (filtered), get, create, update, delete, submit — no cancel
  components/
    GoodsReceiptFiltersBar.tsx        status + date range, server-side (same shape as Purchase's)
    GoodsReceiptLineItemTable.tsx     fixed rows from the selected PO, Receive Now only editable field
  pages/
    GoodsReceiptListPage.tsx
    GoodsReceiptEditorPage.tsx        two-phase: pick a PO, then the document form
    GoodsReceiptDetailPage.tsx
```

Backend (additive only — see §4):

```
app/Http/Requests/IndexGoodsReceiptRequest.php   new
app/Repositories/GoodsReceiptRepository.php      +search() method (paginate() untouched)
app/Services/GoodsReceiptService.php             list() now takes $filters
app/Http/Controllers/Api/V1/GoodsReceiptController.php   index() now injects IndexGoodsReceiptRequest
```

Routes: `/purchase/goods-receipts` (list), `/purchase/goods-receipts/new` (create — starts with PO selection), `/purchase/goods-receipts/:id/edit` (edit, draft-only), `/purchase/goods-receipts/:id` (detail).

---

## 2. Outstanding Quantity Calculation

Purchase Order items already track `qty` (ordered), `received_qty`, and `outstanding_qty = qty - received_qty` — all computed server-side (`PurchaseOrderItemResource`), not derived on the frontend. The Editor's job is only to display this snapshot and bound the input against it:

1. When a Purchase Order is selected (or, in edit mode, already known from the Goods Receipt being edited), the frontend fetches that PO **fresh** via `fetchPurchaseOrder(id)` — never reused from a cached list — so `outstanding_qty` reflects the true current state, including any other Goods Receipts submitted since the PO was last loaded.
2. Each PO line becomes one fixed row: `ordered = poItem.qty`, `alreadyReceived = poItem.received_qty`, `remaining = poItem.outstanding_qty`.
3. **Receive Now** is validated client-side (`goodsReceiptFormSchema`'s `superRefine`) to be a whole number between `0` and `remaining` — matching the backend's own `assertWithinOutstanding()` check in `GoodsReceiptService`, so the same rule is enforced in both places rather than only server-side.

**Why receiving doesn't need to account for the current draft's own pending quantity:** `received_qty` on a Purchase Order item is only incremented when a Goods Receipt is **submitted** (`PurchaseOrderItemRepository::incrementReceivedQty()`, called from `GoodsReceiptService::submit()`), never when a Goods Receipt is merely saved as a draft. So a draft's `outstanding_qty` snapshot, fetched fresh, already correctly represents the true remaining capacity — no adjustment needed when editing an existing draft, `remaining` from the fresh PO fetch is always the real ceiling.

---

## 3. Partial Receipt Behavior

Explicitly required — "Support partial receipts naturally" — and true by construction, not a special case:

- A Goods Receipt can include **any** subset of a Purchase Order's lines, at **any** quantity from `0` up to that line's `remaining`. Lines left at `0` are simply excluded from the API payload (`items.filter((line) => Number(line.receiveNow) > 0)`) — the backend's `items` array only ever contains what's actually being received in this document.
- After a partial receipt is submitted, the Purchase Order stays `submitted` (not fully received) and remains selectable for a **subsequent** Goods Receipt — verified end-to-end: created a 20-unit PO, received 12 in one Goods Receipt, confirmed via the API that the PO's `outstanding_qty` correctly dropped to `8` while `status` stayed `submitted` and `is_fully_received` stayed `false`.
- The Editor's "Select Purchase Order" step filters to `status === 'submitted' && !is_fully_received` — a PO with any remaining quantity on any line stays eligible for another receipt; a fully-received PO drops out of the list automatically (computed server-side by `PurchaseOrderResource`'s `is_fully_received`, not re-derived on the frontend).
- Multiple Goods Receipts against the same PO are ordinary, independent documents — nothing in the data model treats a "final" receipt differently from a partial one; the PO simply becomes ineligible once every line's `outstanding_qty` reaches zero.

---

## 4. API Integration

| Action | Endpoint |
|---|---|
| List (filtered) | `GET /goods-receipts?search=&status=&date_from=&date_to=&per_page=&page=` |
| Get one | `GET /goods-receipts/{id}` |
| Create | `POST /goods-receipts` |
| Update (draft only) | `PUT /goods-receipts/{id}` |
| Delete (draft only) | `DELETE /goods-receipts/{id}` |
| Submit | `POST /goods-receipts/{id}/submit` |

**No cancel endpoint** — `GoodsReceipt::cancel()` unconditionally throws ("Reversal is only available through the Return workflow, not yet implemented"), and the controller deliberately has no `cancel` route. The frontend reflects this exactly: `goodsReceiptApi.ts` has no `cancelGoodsReceipt` function, and neither the List row actions nor the Detail page ever offer a Cancel button, for any status.

Dropdown/lookup data: `GET /purchase-orders?status=submitted&per_page=100` (candidate POs, filtered client-side to `!is_fully_received`), `GET /warehouses` (page 1, via the existing `fetchWarehousesLookup`).

**Backend change made this sprint (pre-authorized, additive-only):** `GoodsReceiptController::index()` had the identical no-filtering gap Purchase Order had before Phase 2C — no query parameters accepted at all. Added `IndexGoodsReceiptRequest` (search, status, date_from, date_to, per_page), `GoodsReceiptRepository::search()` mirroring `PurchaseOrderRepository::search()`'s `when()`-chain exactly, and threaded filters through `GoodsReceiptService::list()`. Verified directly against the endpoint via curl (status filter, search, invalid-status 422) before any frontend code was written. No business logic, schema, or lifecycle code touched.

---

## 5. Reusable Components

**Reused as-is:** `PageHeader`, `ActionBar`, `SearchBox`, `FilterPanel`, `DataTable` (List rows, and read-only for Detail's line items — no `ordered`/`remaining` columns there, just what was actually received), `RowActionsMenu`, `Pagination`, `StatusBadge`, `DeleteDialog`, `DetailField`/`DetailSection`, `Card`/`Textarea`/`Select` from the document-editor shell established in Phase 2C.

**New:** `GoodsReceiptFiltersBar` and `GoodsReceiptLineItemTable` — entity-specific, following the established per-entity pattern rather than being forced into shared components (`GoodsReceiptLineItemTable` in particular is deliberately *not* `PurchaseOrderLineItemTable` reused — no item lookup, no add/remove row, fixed row count from the source PO; see §6).

**Retrofitted (not redesigned):** `SectionNav` added to `PurchaseOrderListPage` — one import and one line — so Orders and Goods Receipts can be navigated between now that Purchase has two sibling document types. Purchase Order's Editor, Detail, and internal layout are unchanged.

---

## 6. Design Decisions & Trade-offs

- **No free item selection, enforced structurally, not just by convention.** The Editor's line-item rows are populated once from the selected Purchase Order's items and never gain an "Add Row" control — there is no code path that lets a user add a line item that didn't come from the PO. This is a stronger guarantee than a validation rule would be: the UI simply has no button to misuse.
- **`GoodsReceiptLineItemTable` is a new component, not `PurchaseOrderLineItemTable` reused.** They look superficially similar (a table of item rows with quantities) but the interaction models are opposites: Purchase Order's table is a free-form `useFieldArray` with Add/Remove/item-lookup; Goods Receipt's is a fixed, PO-derived read-only snapshot with exactly one editable field per row. Forcing one component to serve both would have meant threading a "read-only except this column" mode through every cell — more complex than two small, honest components.
- **The Editor is two-phase within one page, not two routes.** "Select Purchase Order" renders as its own card with nothing else until a PO is chosen; once chosen, the full document form appears. This matches the sprint's literal flow (PO → select → load lines → enter quantities → create) without introducing a wizard framework or a second route for what's still fundamentally one creation action.
- **Same Tax-is-zero handling as Purchase Order**, for the same reason: `GoodsReceiptItemResource` has no tax field. `computeTax()` returns a fixed `0`; Subtotal and Grand Total are computed from `receiveNow × rate` per line (the value of what's actually being received now, not the full remaining value) — shown, not hidden, consistent with Phase 2C's reasoning.
- **Purchase Order's `document_number` is resolved client-side**, same pattern as Warehouse's branch name in Master Data — `GoodsReceiptResource` doesn't nest a `purchase_order` object (only `warehouse` and `supplier` are nested, despite `purchaseOrder` being eager-loaded server-side). Resolved via a `GET /purchase-orders?per_page=100` lookup rather than a further backend change, keeping this sprint's backend touch limited to exactly what was pre-authorized (the `IndexGoodsReceiptRequest` addition).

---

## 7. Screenshots

- Goods Receipt List (Orders/Goods Receipts SectionNav, resolved PO column, 3 receipts): `screenshot-1784266276056-20.jpg`
- Goods Receipt Editor (PO selected, Ordered/Already Received/Remaining/Receive Now, live totals): `screenshot-1784265939396-18.jpg`
- Goods Receipt Editor after Save Draft (Submit button now available): confirmed via accessibility tree (toast "Goods Receipt saved as draft.", GR-00003, Submit button present)
- Goods Receipt Detail (submitted, Purchase Order link, received line items, totals, audit info): confirmed via accessibility tree post-submission (status "Submitted", no actions — terminal, matches §4's no-cancel design)

---

## 8. Recommendation for the Next Workflow

With Purchase → Goods Receipt → (Accounts Payable, already created automatically on submit) complete, two reasonable directions:

1. **Payment Entry**, closing the purchasing side's financial loop — `AccountsPayableService::createFromGoodsReceipt()` already runs on every Goods Receipt submission, so there's real, unpaid AP data waiting the moment this module exists. Same List+Editor+Detail shape likely doesn't apply as cleanly here (Payment Entry settles against *existing* AP records rather than building a new document from scratch), closer in spirit to how Goods Receipt originates from a PO — worth designing the "select outstanding AP records to settle" flow with the same care this sprint gave "select outstanding PO lines to receive."
2. **Sales Order**, mirroring the entire Purchase side (Sales Order → Delivery → Accounts Receivable → Receipt Entry) — every pattern from this sprint and Phase 2C transfers almost directly (Sales Order ≈ Purchase Order, Delivery ≈ Goods Receipt, both already have the same `Index*Request`-shaped gap on their `index()` endpoints today).

Either way, **`SalesOrderController`/`DeliveryController::index()` have the identical no-filtering gap** Purchase Order and Goods Receipt both had — worth deciding upfront (as this sprint's brief did) whether their `Index*Request`s get added alongside the frontend work, now that the pattern is established a third time.
