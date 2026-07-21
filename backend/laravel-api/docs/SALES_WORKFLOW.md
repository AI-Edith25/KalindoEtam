# Sales Workflow (Sprint 5)

The outbound mirror of `docs/PURCHASE_WORKFLOW.md` (Sprint 4) — same shape, same guards, same Document Engine, reused rather than reimplemented.

```
Customer → Sales Order → Delivery → Stock Ledger (−) → Accounts Receivable
```

## Scope

MVP for a building-materials distributor, not a clone of ERPNext Sales. Explicitly **not built**: Quotation, Payment, Journal, tax calculation, discount engine, Returns, credit limit, approval workflow. Postponed until a confirmed business need justifies them — see `docs/DECISIONS.md`.

## Entities

- **SalesOrder** / **SalesOrderItem** — uses `Documentable` (`documentType() = 'sales'`, reusing the `SO-` `NamingSeries` seeded in Sprint 3). Does **not** touch inventory; it's a plan to sell. `SalesOrderItem.delivered_qty` tracks cumulative fulfillment; `outstanding_qty` is computed (`qty - delivered_qty`), never stored.
- **Delivery** / **DeliveryItem** — uses `Documentable` (`documentType() = 'delivery'`, new `DN-` `NamingSeries` added this sprint). This is where physical shipment is recorded, and the only place SO fulfillment turns into a real stock decrease and a real receivable. `DeliveryItem` snapshots `item_code`/`item_name`/`uom`/`rate` at delivery time — same discipline as Sprint 4's `GoodsReceiptItem`, extended to also snapshot `rate` (the price actually agreed on the Sales Order, not whatever `Item.standard_rate` happens to be later).
- **AccountsReceivable** — plain entity (not `Documentable`), created automatically when a Delivery is submitted. Own lifecycle (`Unpaid`/`PartiallyPaid`/`Paid`) via `AccountsReceivableStatus` — a separate enum from Sprint 4's `AccountsPayableStatus` despite identical values, since Payable and Receivable are independently-evolvable domain concepts (see `docs/DECISIONS.md`).

## What's reused, unchanged, from Sprint 0–4

- `Documentable` trait — numbering, Draft/Submitted/Cancelled lifecycle, `DocumentTimeline` recording, `afterCreate`/`afterSubmit`/`afterCancel` hooks.
- `DocumentNumberGeneratorInterface` / `DocumentNumberGeneratorService` — no changes.
- `StockLedgerService::record()` — the **only** thing that moves stock, now exercising `StockTransactionType::OUT` (a negative `qtyChange`) for the first time; it already supported signed quantities, nothing to add.
- `BusinessException` — used for every new guard this sprint, same as Sprint 4's convention (`docs/DECISIONS.md#d-r20`).
- `BaseRepository`, `ApiResponse` trait, the whole Controller → Service → Repository → Model layering — copied structurally, not reinvented.

## What's new

- **`StockLedgerService::getCurrentBalance()`** — a small addition (not present in Sprint 4, which never needed to *read* a balance, only write one). Lets `DeliveryService` check available stock before shipping, through the same single gateway that already owns every stock write.
- **Insufficient-stock guard** — Purchase never needed this (receiving stock can only increase a balance); Delivery can request more than physically exists in the warehouse, so `DeliveryService::submit()` checks `getCurrentBalance() >= qty` per line before moving anything, on top of the outstanding-qty check against the Sales Order.

## The workflow, step by step

1. **Create a Sales Order** against a `Customer`, with one or more lines (`item_id`, `qty`, `rate`). Status `Draft`. `total_amount` computed server-side.
2. **Submit the SO** (`POST /sales-orders/{id}/submit`). Requires ≥1 line. Status → `Submitted`. **No inventory or accounting effect.**
3. **Create a Delivery** against the submitted SO, specifying `warehouse_id` (the source warehouse stock ships *from*), `delivery_date`, `due_date` (manually entered, same rationale as Sprint 4's Goods Receipt), and lines referencing `sales_order_item_id` + qty to ship. Rejected if the SO isn't Submitted, or if any line's qty exceeds that SO line's outstanding qty. Each line snapshots `item_code`/`item_name`/`uom`/`rate` from the SO item.
4. **Submit the Delivery** (`POST /deliveries/{id}/submit`). Inside one `DB::transaction()`:
   - Re-validates the SO is still Submitted and quantities are still within outstanding (state could have changed since step 3).
   - **Checks physical stock is sufficient** for every line (`StockLedgerService::getCurrentBalance()`), rejecting the whole submit if any line would drive the balance negative.
   - For every line, calls `StockLedgerService::record()` with a **negative** `qtyChange` — `DeliveryService` never writes to `Item.current_stock` or `stock_ledgers` directly.
   - Advances `SalesOrderItem.delivered_qty`.
   - Flips status to `Submitted` via `Documentable` (`$delivery->submit()`), recording the `DocumentTimeline` entry.
   - Creates `AccountsReceivable`: `amount` = sum of delivery line amounts, `reference_number` = the Delivery's `document_number`, `due_date` copied from the Delivery, `status` = `Unpaid`.
5. **Accounts Receivable now exists**, read-only, at `GET /accounts-receivables`. Nothing this sprint changes its status.

## What's deliberately guarded

- **Delivery cannot be cancelled.** `Delivery::cancel()` unconditionally throws `BusinessException`; no `POST /deliveries/{id}/cancel` route exists. Identical rationale to `GoodsReceipt`: a submitted delivery has already moved stock and created a receivable, and reversing that correctly is a Return workflow that doesn't exist yet.
- **Sales Order cancel is guarded**: rejected if any line already has `delivered_qty > 0`.
- **Update/delete on SalesOrder and Delivery are Draft-only.**
- **Over-delivery is rejected** (qty vs. SO outstanding), at both create and submit time.
- **Insufficient stock is rejected** (qty vs. physical warehouse balance), checked at submit time — this is the one guard Purchase's workflow structurally could not need and Sales does.
- All violations throw `BusinessException` → `{success:false, message, data:null}` at HTTP 422.

## Manual testing steps

Requires stock already in the warehouse — run the Purchase Workflow first (or at minimum a `PO → Goods Receipt` cycle) so there's something to sell.

1. Ensure Master Data exists: `Customer`, `Warehouse` with stock (via Sprint 4's flow), `Item`.
2. `POST /api/v1/sales-orders` with a customer and one line — confirm `document_number` starts with `SO-`, `status` is `draft`.
3. `POST /api/v1/sales-orders/{id}/submit` — confirm `status` becomes `submitted`.
4. `POST /api/v1/deliveries` referencing that SO's `sales_order_item_id`, with `qty` less than ordered (partial delivery) and `due_date` ≥ `delivery_date`.
5. `POST /api/v1/deliveries/{id}/submit` — confirm response `status` is `submitted`.
6. `GET /api/v1/items/{item}/stock-ledger` — confirm a new entry with `voucher_type: delivery` and a **negative** `qty_change`.
7. `GET /api/v1/items/{item}` — confirm `current_stock` decreased by the delivered qty.
8. `GET /api/v1/sales-orders/{id}` — confirm the line's `delivered_qty`/`outstanding_qty` updated, `is_fully_delivered` is `false` (partial).
9. `GET /api/v1/accounts-receivables?status=unpaid` — confirm a row with `reference_number` matching the Delivery's `document_number`.
10. Try `POST /api/v1/deliveries` again with qty exceeding what's left outstanding on the SO — expect 422.
11. Try delivering an item/warehouse combination with zero or insufficient stock — expect 422 with an "Insufficient stock" message, distinct from the outstanding-qty message.
12. Confirm there is no cancel route for Delivery; `$delivery->cancel()` throws if called anywhere in code.
13. `POST /api/v1/sales-orders/{id}/cancel` on an SO that already has a delivery — expect 422.

## Known limitations

Same list as `docs/PURCHASE_WORKFLOW.md`, mirrored: no payment terms (manual `due_date`), no consolidated multi-SO delivery, `AccountsReceivable.paid_amount`/`PartiallyPaid`/`Paid` unused (Payment sprint), no Return workflow, no tax/discount on Sales Order lines, MySQL still unmigrated locally (`docs/DECISIONS.md#d-r09`).

Additionally:
- **No credit limit check.** A Customer with any payment history (or none) can have unlimited open Sales Orders/Deliveries — explicitly excluded this sprint.
- **Insufficient-stock check is warehouse-specific and point-in-time.** Two Deliveries submitted concurrently against the same warehouse could both pass the check before either writes — `StockLedgerService::record()`'s own `lockForUpdate()` (Sprint 1) prevents the resulting balance from being wrong, but the *second* submit could still succeed into a balance that was sufficient when checked and is no longer generous by the time it runs, if a third actor drained it in between. Acceptable for MVP single-operator use; revisit if concurrent multi-user delivery becomes real.

## Recommended next sprint

**Payment Entry** — the natural next step now that both `AccountsPayable` and `AccountsReceivable` exist with real data flowing into them from two independent workflows. A single Payment module (apply a payment to one or more AP/AR rows, update `paid_amount`, transition `Unpaid → PartiallyPaid → Paid`) closes the loop on both Purchase and Sales at once, rather than building it twice. Journal Entry and Returns remain postponed until Payment proves the `paid_amount`/status mechanics are right.
