# API — Foundation, Inventory, Master Data, Document Engine, Purchase, Sales, Payment Workflow & Dashboard

Base path: `/api/v1`. All responses use the shape mandated by `.ia/05_API_GUIDELINES.md`:

```json
{
  "success": true,
  "message": "",
  "data": {}
}
```

**Paginated list endpoints** (any `index()` backed by a `LengthAwarePaginator`) additionally include a top-level `meta` object — fixed in Sprint 7, see `docs/DECISIONS.md#d-r31`:

```json
{
  "success": true,
  "message": "",
  "data": [ ... ],
  "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 }
}
```

Validation errors follow Laravel's default FormRequest 422 shape (`message`, `errors`).

**Business rule violations** (a well-formed request that violates a workflow invariant — over-receipt, insufficient stock, cancelling a settled document, etc.) return the same `{success:false, message, data:null}` envelope at HTTP 422, via `App\Exceptions\BusinessException`. This is distinct from FormRequest validation errors (malformed input) and is used consistently across every workflow introduced since Sprint 4 — verified project-wide in Sprint 7, see `docs/INTEGRATION_CHECKLIST.md`.

## Company

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `/companies` | — | Paginated (15/page) |
| POST | `/companies` | `name, code, address?, phone?, email?, currency?, timezone?, fiscal_year_start` | |
| GET | `/companies/{id}` | — | |
| PUT/PATCH | `/companies/{id}` | any of the above, all optional | |
| DELETE | `/companies/{id}` | — | Soft delete |

## Branch

| Method | Endpoint | Body |
|---|---|---|
| GET | `/branches` | — |
| POST | `/branches` | `company_id, name, code, address?, is_head_office?` |
| GET | `/branches/{id}` | — |
| PUT/PATCH | `/branches/{id}` | partial |
| DELETE | `/branches/{id}` | — |

## Warehouse

| Method | Endpoint | Body |
|---|---|---|
| GET | `/warehouses` | — |
| POST | `/warehouses` | `name, code, warehouse_type` (`main`\|`transit`\|`return`) |
| GET | `/warehouses/{id}` | — |
| PUT/PATCH | `/warehouses/{id}` | partial |
| DELETE | `/warehouses/{id}` | — |

## Role & Permission

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `/roles` | — | Includes `permissions` (names) |
| POST | `/roles` | `name, guard_name?` | |
| GET | `/roles/{id}` | — | |
| PUT/PATCH | `/roles/{id}` | `name?` | |
| DELETE | `/roles/{id}` | — | |
| POST | `/roles/{id}/permissions` | `permissions: string[]` | Replaces the role's permission set (sync, not append) |
| GET | `/permissions` | — | Read-only; permissions are seeded per module, not created ad-hoc via API |

Permission names follow `{module}.{action}` (e.g. `company.view`, `item.create`). See [FOUNDATION.md](FOUNDATION.md#roles--permissions) for the full seeded list.

## Master Data (Sprint 2)

Same CRUD shape for all six — `apiResource`, paginated `index`, soft-deleting `destroy`. No Purchase/Sales endpoints exist yet, so none of these are referenced by a transaction this round.

| Entity | Endpoint | Body |
|---|---|---|
| Item Group | `/item-groups` | `name, description?` |
| Unit of Measurement | `/uoms` | `name, symbol?` |
| Currency | `/currencies` | `code, name, symbol?, exchange_rate?` |
| Tax | `/taxes` | `name, rate, is_active?` |
| Customer | `/customers` | `customer_code, customer_name, phone?, email?, address?, is_active?` |
| Supplier | `/suppliers` | `supplier_code, supplier_name, phone?, email?, address?, is_active?` |

`Tax.rate` is a percentage (0–100). `Currency.exchange_rate` is the current rate against the base currency, not a dated history (see `docs/DECISIONS.md#d-r04`).

## Item

| Method | Endpoint | Body |
|---|---|---|
| GET | `/items` | — |
| POST | `/items` | `item_code, item_name, item_group_id, uom_id, standard_rate?` |
| GET | `/items/{id}` | — |
| PUT/PATCH | `/items/{id}` | partial |
| DELETE | `/items/{id}` | — |

`current_stock` is **not** an accepted input field — it is a cache maintained exclusively by `StockLedgerService`. `item_group_id`/`uom_id` must reference an existing `ItemGroup`/`UnitOfMeasurement` (Sprint 2 — previously these were free-text `item_group`/`stock_uom` strings). Responses embed the related `item_group`/`uom` objects when eager-loaded (`index` and `show` always do).

## Stock In

| Method | Endpoint | Body |
|---|---|---|
| POST | `/stock-in` | `date, warehouse_id, remarks?, items: [{item_id, qty}]` |

Each line creates a `StockIn` record and, via `StockLedgerService`, a corresponding `StockLedger` entry (`transaction_type: in`) that updates `Item.current_stock`.

## Stock Ledger (read-only history)

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/items/{item}/stock-ledger` | Paginated, newest first — "Riwayat" per `01_BUSINESS_REQUIREMENTS.md` |

## Document Engine (Sprint 3)

See `docs/DOCUMENT_ENGINE.md` for how a future module (Purchase/Sales/Invoice/Journal) consumes this. Every future document's own numbering/status/timeline/attachment/approval must go through it, not a bespoke implementation.

### Naming Series

Full CRUD master, same shape as other Sprint 1/2 masters.

| Method | Endpoint | Body |
|---|---|---|
| GET | `/naming-series` | — |
| POST | `/naming-series` | `module, document_type, prefix?, suffix?, digit_length?, is_default?, is_active?` |
| GET | `/naming-series/{id}` | — |
| PUT/PATCH | `/naming-series/{id}` | partial |
| DELETE | `/naming-series/{id}` | — |

`current_number` is **not** an accepted input field — only `DocumentNumberGeneratorService` advances it, when a real document is created.

### Attachments (generic, polymorphic)

| Method | Endpoint | Body / Query | Notes |
|---|---|---|---|
| GET | `/attachments` | `attachable_type, attachable_id` (query, required) | Paginated |
| POST | `/attachments` | `attachable_type, attachable_id, file` (multipart) | Stores on the `local` disk under `attachments/` |
| DELETE | `/attachments/{id}` | — | Also removes the physical file |

No `update` — replacing an attachment is delete + re-upload.

### Document Timeline (read-only)

| Method | Endpoint | Query | Notes |
|---|---|---|---|
| GET | `/document-timeline` | `subject_type, subject_id` (required) | Paginated, newest first |

Entries are written only by `DocumentTimelineService`, called internally by the `Documentable` trait (`created`/`submit`/`cancel`) — there is no POST endpoint, so the timeline can't be forged through the API.

### Approval Flow

No API yet — structure only (`approval_flows` table + `ApprovalFlow` model). See `docs/DOCUMENT_ENGINE.md`.

## Purchase Workflow (Sprint 4)

See `docs/PURCHASE_WORKFLOW.md` for the full Supplier → PO → Goods Receipt → Stock Ledger(+) → Accounts Payable flow, manual testing steps, and limitations.

### Purchase Order

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `/purchase-orders` | — | Paginated, eager-loads `supplier`, `items.item` |
| POST | `/purchase-orders` | `supplier_id, order_date, expected_delivery_date?, remarks?, items: [{item_id, qty, rate}]` | `total_amount` computed server-side |
| GET | `/purchase-orders/{id}` | — | |
| PUT/PATCH | `/purchase-orders/{id}` | partial; `items` (if present) fully replaces existing lines | **Draft only** |
| DELETE | `/purchase-orders/{id}` | — | **Draft only** |
| POST | `/purchase-orders/{id}/submit` | — | Requires ≥1 item. Does **not** touch inventory. |
| POST | `/purchase-orders/{id}/cancel` | — | Rejected if any line has `received_qty > 0` |

### Goods Receipt

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `/goods-receipts` | — | Paginated, eager-loads `supplier`, `warehouse`, `purchaseOrder`, `items` |
| POST | `/goods-receipts` | `purchase_order_id, warehouse_id, receipt_date, due_date, remarks?, items: [{purchase_order_item_id, qty}]` | PO must be Submitted; `due_date >= receipt_date`; rejects qty over the PO line's outstanding qty |
| GET | `/goods-receipts/{id}` | — | |
| PUT/PATCH | `/goods-receipts/{id}` | partial | **Draft only** |
| DELETE | `/goods-receipts/{id}` | — | **Draft only** |
| POST | `/goods-receipts/{id}/submit` | — | The workflow: `StockLedgerService` increases stock, `PurchaseOrderItem.received_qty` advances, status → Submitted, `AccountsPayable` created |

**No cancel endpoint, deliberately.** `GoodsReceipt::cancel()` is overridden to always throw `BusinessException` — see `docs/PURCHASE_WORKFLOW.md` and `docs/DECISIONS.md`.

`item_id`/`rate` are **not** accepted on Goods Receipt line input — both are resolved server-side from the referenced `purchase_order_item_id` (rate is a price snapshot, never user-editable at receipt time).

### Accounts Payable (read-only)

| Method | Endpoint | Query | Notes |
|---|---|---|---|
| GET | `/accounts-payables` | `status?, supplier_id?` | Paginated |
| GET | `/accounts-payables/{id}` | — | |

No store/update/destroy — rows exist only as a side effect of `POST /goods-receipts/{id}/submit`. `status` is always `unpaid` this sprint (no payment module).

## Sales Workflow (Sprint 5)

See `docs/SALES_WORKFLOW.md` for the full Customer → SO → Delivery → Stock Ledger(−) → Accounts Receivable flow, manual testing steps, and limitations. Mirrors the Purchase Workflow's shape exactly — same guards, same envelope, same `Documentable` usage — the only new mechanic is `DeliveryService`'s insufficient-stock check (Purchase never needed one; receiving stock can't go negative, shipping it can).

### Sales Order

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `/sales-orders` | — | Paginated, eager-loads `customer`, `items.item` |
| POST | `/sales-orders` | `customer_id, order_date, expected_delivery_date?, remarks?, items: [{item_id, qty, rate}]` | `total_amount` computed server-side |
| GET | `/sales-orders/{id}` | — | |
| PUT/PATCH | `/sales-orders/{id}` | partial; `items` (if present) fully replaces existing lines | **Draft only** |
| DELETE | `/sales-orders/{id}` | — | **Draft only** |
| POST | `/sales-orders/{id}/submit` | — | Requires ≥1 item. Does **not** touch inventory. |
| POST | `/sales-orders/{id}/cancel` | — | Rejected if any line has `delivered_qty > 0` |

### Delivery

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `/deliveries` | — | Paginated, eager-loads `customer`, `warehouse`, `salesOrder`, `items` |
| POST | `/deliveries` | `sales_order_id, warehouse_id, delivery_date, due_date, remarks?, items: [{sales_order_item_id, qty}]` | SO must be Submitted; `due_date >= delivery_date`; rejects qty over the SO line's outstanding qty |
| GET | `/deliveries/{id}` | — | |
| PUT/PATCH | `/deliveries/{id}` | partial | **Draft only** |
| DELETE | `/deliveries/{id}` | — | **Draft only** |
| POST | `/deliveries/{id}/submit` | — | The workflow: outstanding-qty check, **insufficient-stock check**, `StockLedgerService` decreases stock, `SalesOrderItem.delivered_qty` advances, status → Submitted, `AccountsReceivable` created |

**No cancel endpoint, deliberately.** `Delivery::cancel()` is overridden to always throw `BusinessException` — identical rationale to `GoodsReceipt` in Sprint 4. See `docs/SALES_WORKFLOW.md` and `docs/DECISIONS.md`.

`item_id`/`rate` are **not** accepted on Delivery line input — both are resolved server-side from the referenced `sales_order_item_id` (rate is a price snapshot from the Sales Order, never user-editable at delivery time).

### Accounts Receivable (read-only)

| Method | Endpoint | Query | Notes |
|---|---|---|---|
| GET | `/accounts-receivables` | `status?, customer_id?` | Paginated |
| GET | `/accounts-receivables/{id}` | — | |

No store/update/destroy — rows exist only as a side effect of `POST /deliveries/{id}/submit`. `status`/`paid_amount` are now advanced by Sprint 6's Payment Workflow — see below.

## Payment Workflow (Sprint 6)

See `docs/PAYMENT_WORKFLOW.md`. This sprint is settlement only — no Journal Entry, no General Ledger, no accounting automation. It updates `AccountsPayable`/`AccountsReceivable` (Sprint 4/5) in place; those two entities' own endpoints remain read-only.

### Payment Entry (Supplier)

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `/payment-entries` | — | Paginated, eager-loads `supplier`, `items.accountsPayable` |
| POST | `/payment-entries` | `supplier_id, payment_date, payment_method, reference_number?, remarks?, items: [{accounts_payable_id, paid_amount}]` | `payment_method`: `cash`\|`bank_transfer`\|`cheque`. `total_amount` computed server-side. Rejects `paid_amount <= 0`, `paid_amount` over the AP's outstanding, the same `accounts_payable_id` twice, or an AP belonging to a different supplier. |
| GET | `/payment-entries/{id}` | — | |
| PUT/PATCH | `/payment-entries/{id}` | partial; `items` (if present) fully replaces existing lines | **Draft only** |
| DELETE | `/payment-entries/{id}` | — | **Draft only** |
| POST | `/payment-entries/{id}/submit` | — | Re-validates each line against the AP's *current* outstanding, then calls `AccountsPayableService::settle()` per line (advances `paid_amount`, recomputes `status`), then flips to Submitted. |

**No cancel endpoint, deliberately.** `PaymentEntry::cancel()` always throws `BusinessException` — a submitted payment has already reduced AP balances; reversing it needs a void workflow that doesn't exist yet (same rationale as `GoodsReceipt`/`Delivery`).

`reference_number` here is a **user-entered** external reference (bank transfer no., cheque no.) — not a system snapshot, unlike `AccountsPayable.reference_number`.

### Receipt Entry (Customer)

| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `/receipt-entries` | — | Paginated, eager-loads `customer`, `items.accountsReceivable` |
| POST | `/receipt-entries` | `customer_id, receipt_date, payment_method, reference_number?, remarks?, items: [{accounts_receivable_id, received_amount}]` | Same validation shape as Payment Entry, mirrored for AR. |
| GET | `/receipt-entries/{id}` | — | |
| PUT/PATCH | `/receipt-entries/{id}` | partial | **Draft only** |
| DELETE | `/receipt-entries/{id}` | — | **Draft only** |
| POST | `/receipt-entries/{id}/submit` | — | Re-validates, calls `AccountsReceivableService::settle()` per line, flips to Submitted. |

**No cancel endpoint** — identical rationale to Payment Entry.

### Effect on Accounts Payable / Accounts Receivable

`AccountsPayableResource`/`AccountsReceivableResource` (Sprint 4/5, unchanged shape) now reflect real values after any Payment/Receipt Entry is submitted: `paid_amount` increases, `outstanding_amount` (`amount - paid_amount`) shrinks, `status` moves `unpaid` → `partially_paid` → `paid`. Query them at `GET /accounts-payables` / `GET /accounts-receivables` to see the effect — there's no separate "settlement history" endpoint; the trail is the `PaymentEntry`/`ReceiptEntry` records themselves plus each's `DocumentTimeline`.

## Dashboard (Sprint 7)

Read-only aggregates over the six existing workflow entities — no new tables, no charts (per instruction: "Only provide aggregated API endpoints"). Each endpoint is its own lightweight query, not one large combined payload, so a frontend can load/cache/poll widgets independently.

| Method | Endpoint | Query | Returns |
|---|---|---|---|
| GET | `/dashboard/stock-summary` | — | `{total_items, total_stock_qty, zero_stock_items}` |
| GET | `/dashboard/purchases-today` | `date?` (default today) | `{date, total_amount, count}` — sum of `PurchaseOrder.total_amount` where `order_date` = date |
| GET | `/dashboard/sales-today` | `date?` (default today) | Same shape, `SalesOrder` |
| GET | `/dashboard/accounts-payable-outstanding` | — | `{total_outstanding, count}` — `SUM(amount - paid_amount)` where `status != paid` |
| GET | `/dashboard/accounts-receivable-outstanding` | — | Same shape, `AccountsReceivable` |
| GET | `/dashboard/low-stock-items` | `threshold?` (default 10) | Paginated `ItemResource` list where `current_stock <= threshold` |
| GET | `/dashboard/recent-transactions` | `limit?` (default 20, max 100) | Array of `{type, document_number, date, amount, status, created_at}` merged across all 6 workflow entities, newest first |

Notes:

- **"Purchase"/"Sales" totals are order-value, not received/delivered actuals.** `purchases-today` sums `PurchaseOrder.total_amount` (what was ordered), not Goods Receipt value (what physically arrived) — the two can differ once a PO is only partially received. Documented, not a bug; revisit if the frontend actually needs the receipt-actuals variant.
- **`low-stock-items` has no persisted threshold.** `Item` has no `minimum_stock`/`reorder_level` column — deliberately not added this sprint (stabilization, not new features). The caller supplies `threshold` per request. See `docs/DECISIONS.md#d-r34`.
- **`recent-transactions` has no `amount` column on `GoodsReceipt`/`Delivery` headers** (unlike `PurchaseOrder`/`SalesOrder`/`PaymentEntry`/`ReceiptEntry`, which cache `total_amount`) — their entries compute `items->sum('amount')` from an eager-loaded `items` relation instead. Bounded cost: each type is fetched at most `limit` rows before merging, not scanned in full.
- No FormRequest accepts `perPage` for `low-stock-items` — uses the same default-15 pagination as every other list endpoint in this API.
