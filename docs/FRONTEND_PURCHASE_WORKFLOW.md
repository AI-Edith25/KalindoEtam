# Frontend — Purchase Workflow

Phase 2C. First transactional-document module, built on the ERP Design System (`docs/ERP_DESIGN_SYSTEM.md`) but deliberately different from Master Data where the two concepts diverge: Purchase Orders have a lifecycle (draft → submitted → cancelled) and a full-page document editor instead of a Drawer.

---

## 1. Folder Structure

```
features/purchase/
  types.ts                          PurchaseOrder, PurchaseOrderItem, form value types, filter type
  lib/
    purchaseOrderFilters.ts         emptyPurchaseOrderFilters, hasActivePurchaseOrderFilters
    purchaseOrderFormSchema.ts      zod schema + inferred PurchaseOrderEditorValues (shared by Editor + line-item table)
    purchaseTotals.ts               lineAmount, computeSubtotal, computeTax, computeGrandTotal
  api/
    purchaseOrderApi.ts             fetchPurchaseOrders(filters), fetchPurchaseOrder, create, update, delete, submit, cancel
  components/
    PurchaseOrderFiltersBar.tsx     Status + date-range controls, sent straight to the server (no client applyFilters here)
    PurchaseOrderLineItemTable.tsx  the editable line-item grid (react-hook-form useFieldArray)
  pages/
    PurchaseOrderListPage.tsx
    PurchaseOrderEditorPage.tsx     handles both /purchase/orders/new and /purchase/orders/:id/edit
    PurchaseOrderDetailPage.tsx
```

New shared infrastructure (used beyond just Purchase — see §2):

```
components/ui/textarea.tsx          native <textarea>, styled to match Input
features/master/api/lookupsApi.ts   +fetchItemsLookup, +fetchSuppliersLookup (cross-feature reuse)
```

Backend (additive only — see §5):

```
app/Http/Requests/IndexPurchaseOrderRequest.php   new
app/Repositories/PurchaseOrderRepository.php      +search() method (paginate() untouched)
app/Services/PurchaseOrderService.php             list() now takes $filters
app/Http/Controllers/Api/V1/PurchaseOrderController.php   index() now injects IndexPurchaseOrderRequest
```

Routes: `/purchase/orders` (list), `/purchase/orders/new` (create), `/purchase/orders/:id/edit` (edit, draft-only), `/purchase/orders/:id` (detail). Sidebar's "Purchase" now points at `/purchase/orders`.

---

## 2. Shared Components Reused / Added

**Reused as-is from the design system:** `PageHeader`, `ActionBar` (Export/Import still disabled), `SearchBox`, `FilterPanel` (wraps the status/date controls), `DataTable` (List page's rows, and read-only in the Detail page's line-items table), `RowActionsMenu` (status-aware action sets), `Pagination`, `StatusBadge` (document lifecycle colors already existed from Sprint 1), `DeleteDialog`, and `DetailField`/`DetailSection` from `DetailDrawerLayout.tsx` — reused **outside** a Drawer for the first time, proving those two were never Drawer-specific to begin with.

**New:**
- `components/ui/textarea.tsx` — needed for the Notes/remarks field; native HTML element, no new dependency.
- `fetchItemsLookup` / `fetchSuppliersLookup` in `features/master/api/lookupsApi.ts` — the Editor needs "all items" and "all suppliers" for its dropdowns, and Master Data already owns those lookups (same page-1-only ceiling as every other lookup in this codebase). Reusing across feature folders here was more honest than duplicating a second copy of `fetchLookupList('/items')` inside `features/purchase/`.

**Not reused / not extracted this round:**
- `useEntityListPage` — doesn't fit. That hook's contract is client-side filtering over one loaded page; Purchase's filtering is server-side (see §4). `PurchaseOrderListPage` has its own `useState`/`useQuery` wiring instead. If a second server-filtered list page shows up (Sales is the likely next one), *that's* the point to extract a `useServerFilteredListPage` hook — not before, per the same "wait for the third instance" reasoning `useEntityListPage` itself followed in Phase 2B.
- `createCrudApi` — Purchase Orders aren't a plain CRUD resource; they have `submit`/`cancel` actions and a list endpoint with real filters, both outside that factory's shape. `purchaseOrderApi.ts` is hand-written instead.

---

## 3. Purchase Document Architecture

Three pages instead of List + Drawer:

| Page | Route | Purpose |
|---|---|---|
| List | `/purchase/orders` | Server-filtered/paginated table, row actions |
| Editor | `/purchase/orders/new`, `/purchase/orders/:id/edit` | Full-page document form — **not a Drawer**, per this sprint's explicit requirement |
| Detail | `/purchase/orders/:id` | Read-only, section-grouped view with status-appropriate actions |

The Editor is document-style, not form-in-a-box: three stacked `Card`s (Header details, Line Items, Totals) inside one `<form>`, with the action buttons (Cancel/Save Draft/Submit) at the very bottom — reading top-to-bottom like the paper purchase order it represents, matching the sprint's Header/Body/Footer structure exactly.

**Header fields:** Supplier (Select), Order Date, Expected Delivery Date, Notes (Textarea), and a `StatusBadge` showing the current status (or `draft` for a not-yet-saved new order, since that's what it will be). **Warehouse is not in the header** — see §5 for why.

---

## 4. Line Item Editor

`PurchaseOrderLineItemTable.tsx`, built on `react-hook-form`'s `useFieldArray` rather than `DataTable`:

- **Add Row** appends `{ item_id: '', qty: '1', rate: '0' }`.
- **Remove Row** — a trash icon per row, always available (no minimum-one-row lock while editing; the zod schema's `items.min(1)` is enforced at submit time, not per-keystroke).
- **Item lookup** is a `Select` populated from `fetchItemsLookup()`, showing `CODE — Name` so both the code (fast recognition) and the readable name are visible without a second column.
- **Autofill on item pick:** selecting an item calls `form.setValue('items.{index}.rate', item.standard_rate)` — one click gets a sensible starting price, still fully editable afterward.
- **Amount** is derived, not stored in the form — `lineAmount(watchedItems[index])` computed live via `useWatch`, never out of sync with Qty/Rate.
- **Running Subtotal** in the Editor's footer is the same `computeSubtotal()` over all watched rows, recalculating on every keystroke.

**Keyboard usage:** every control is a native `<input>`/button in natural DOM tab order — Tab moves through Item → Qty → Rate → next row without any custom key handling. Radix `Select` already supports type-to-jump (typing "SEM" jumps to "ITM01 — Semen...") for free. Spreadsheet-style arrow-key cell navigation was considered and **not** built — that's a meaningfully bigger interaction model change than "where practical" calls for for a first version; noted as a future enhancement if line items regularly run long.

**Numeric fields stay strings in the form** (`qty`, `rate`), validated with `.refine()`, converted to numbers only when building the API payload — the same pattern documented in `ERP_DESIGN_SYSTEM.md` §6, now proven on an array field too, not just flat ones.

---

## 5. API Integration

| Action | Endpoint |
|---|---|
| List (filtered) | `GET /purchase-orders?search=&status=&date_from=&date_to=&per_page=&page=` |
| Get one | `GET /purchase-orders/{id}` |
| Create | `POST /purchase-orders` |
| Update (draft only) | `PUT /purchase-orders/{id}` |
| Delete (draft only) | `DELETE /purchase-orders/{id}` |
| Submit | `POST /purchase-orders/{id}/submit` |
| Cancel | `POST /purchase-orders/{id}/cancel` |

Dropdown data: `GET /suppliers` and `GET /items` (page 1 only, via `fetchLookupList`).

**Backend change made this sprint (explicitly approved, additive-only):** `PurchaseOrderController::index()` previously took no query parameters at all — every master-data-style list endpoint in this codebase has that gap, but Purchase Order is a transactional document like `AccountsPayable`/`AccountsReceivable`, which already had `Index*Request` filtering. This sprint closed that specific gap by adding `IndexPurchaseOrderRequest` (search, status, date_from, date_to, per_page — all `sometimes`/`nullable`), a `PurchaseOrderRepository::search()` method mirroring `AccountsPayableRepository::search()`'s `when()`-chain style exactly, and threading filters through `PurchaseOrderService::list()`. No business logic, database schema, or lifecycle code was touched — `create`/`update`/`submit`/`cancel`/`delete` are byte-for-byte unchanged. Purchase Order's list endpoint now has the same API contract shape as AP/AR; Sales Order, Goods Receipt, Delivery, Payment Entry, and Receipt Entry still don't, and would need the identical treatment if/when their own list pages need real filtering.

Verified directly against the endpoint (not just through the UI): status filter, date range, search, and per_page all confirmed working via curl before any frontend code was written, plus one 422 check that an invalid status value is still rejected.

---

## 6. Lifecycle Handling

Backend enum: `draft → submitted → cancelled` (`App\Enums\DocumentStatus`). The frontend never sets status directly — it only calls the action that causes a transition:

| Status | List row actions | Detail page actions | Editor availability |
|---|---|---|---|
| `draft` | View, Edit, Submit, Delete | Edit, Submit, Delete | Full access — this is the only status the Editor's `/:id/edit` route allows |
| `submitted` | View, Cancel | Cancel | Blocked — see guard below |
| `cancelled` | View | *(none — terminal state)* | Blocked |

**Save Draft** is just the normal `create`/`update` call — the backend already defaults new orders to `draft` and rejects updates to anything else, so the frontend doesn't need to send or manage a status field at all during editing.

**Submit** only appears once a draft has been saved (needs a real `id` to call `POST /purchase-orders/{id}/submit`) — a brand-new, unsaved order shows only Save Draft. After a successful create, the Editor navigates itself to `/purchase/orders/{newId}/edit` (not back to the list) specifically so Submit becomes available immediately without an extra trip through the Detail page.

**Guard against editing a non-draft order:** if `/purchase/orders/:id/edit` is opened for an order that isn't `draft` (submitted/cancelled, or reached by a stale link/back-button), the Editor detects this once the order loads, shows an error toast ("Only draft purchase orders can be edited."), and redirects to the read-only Detail page — mirroring the backend's own `assertDraft()` guard so the UI never lets a user attempt an edit the API would reject anyway.

Both `submit` and `cancel` surface backend `BusinessException`s (e.g. "Cannot submit a Purchase Order without items," "Cannot cancel a Purchase Order that already has goods received against it.") through the existing global toast error handler — no special-case handling needed on the frontend.

---

## 7. Design Decisions & Trade-offs

- **Warehouse is not shown anywhere in Purchase Order UI.** The sprint brief lists it as a List column and an Editor header field, but `PurchaseOrder` has no `warehouse_id` — warehouse is chosen at **Goods Receipt** time, not at ordering time (per the existing backend comment: "Purchase Workflow: Supplier → PO → Goods Receipt → Stock Ledger(+) → Accounts Payable"). Adding a warehouse field to Purchase Order would be a real schema and business-logic change, explicitly out of scope for an "additive only" sprint. Omitted rather than faked.
- **Tax is shown as a fixed Rp 0 row, not omitted.** `PurchaseOrder` has no tax column or `tax_id` relation at all — `total_amount` is a pure `qty × rate` sum. Rather than dropping the Tax row (which would break the Header/Body/Footer structure the sprint asked for) or inventing a fake tax calculation (which would misrepresent real money), the footer shows Subtotal / Tax (always 0) / Grand Total (= Subtotal) — honest about the current ceiling, and `computeTax()` is a single function to update everywhere once the backend gains real tax support.
- **The backend filtering gap was flagged and fixed, not worked around.** Unlike Master Data (Sprint 2A.1/2B), where client-side filtering was the right call because *no* master-data list endpoint had filtering, here the sprint explicitly required "server-side filtering" for a document type whose siblings (AP/AR) already had it — client-side filtering would have been inconsistent with an established, nearby convention rather than accepting a project-wide one. Confirmed with the user before touching the backend; kept strictly additive per their scope (no business logic, no database, no lifecycle changes).
- **List sorting is still client-side**, even though filtering/pagination are server-side now — sorting the ~15 rows already correctly filtered and loaded is honest (it only reorders true results, never hides or fabricates any), unlike client-side *filtering* would be. No backend sort param was added because nothing asked for one and the current behavior isn't misleading.

---

## 8. Screenshots

- Purchase List (filters, 3 orders with mixed statuses): `screenshot-1784263319941-16.jpg`
- Purchase Editor (new order, line item + live totals): `screenshot-1784263148944-13.jpg`
- Purchase Editor after Save Draft (Submit button now available): `screenshot-1784263239544-14.jpg`
- Purchase Detail (submitted, read-only, Cancel action): `screenshot-1784263263891-15.jpg`

---

## 9. Recommendation for Goods Receipt (Next Workflow)

Goods Receipt is the natural next module — it's the other half of the workflow comment already in the backend routes file ("PO → Goods Receipt → Stock Ledger(+) → Accounts Payable"), and it's where **Warehouse finally belongs** in the UI (resolving this sprint's omission, not duplicating it). Concretely:

1. **Reuse this sprint's shape wholesale**: List + full-page Editor + Detail, not a Drawer — same reasoning applies (transactional document, has a lifecycle).
2. **Goods Receipt's line items are driven by an existing Purchase Order's outstanding quantities**, not a free item lookup — the Editor will need a "select PO, pull its outstanding lines" flow, a genuinely new interaction this sprint's line-item table doesn't cover as-is (though `lineAmount`/`computeSubtotal` and the react-hook-form `useFieldArray` pattern should still apply directly).
3. **This is where Warehouse becomes real** — `GoodsReceiptResource` already has `warehouse_id`; that's the natural home for the Select this sprint had to omit.
4. **Goods Receipt submission has real side effects** (stock ledger entries, Accounts Payable creation) — the Detail page should surface those outcomes (e.g. a link to the resulting AP record) once they exist, more than Purchase Order's Detail needed to.
5. **Same backend-filtering question will come up again** — `GoodsReceiptController::index()` has the identical no-filtering gap Purchase Order had. Worth deciding once, upfront, whether to add its `Index*Request` alongside Goods Receipt's frontend work rather than after, now that the pattern is established twice over (AP/AR, then Purchase Order).
