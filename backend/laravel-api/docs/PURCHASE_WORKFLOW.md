# Purchase Workflow (Sprint 4)

The first complete, manually-testable business workflow in the system. Everything before this sprint was framework/infrastructure (Foundation, Master Data, Document Engine); this is the first module built *on top of* that infrastructure end to end.

```
Supplier → Purchase Order → Goods Receipt → Stock Ledger (+) → Accounts Payable
```

## Scope

Deliberately an MVP for a building-materials distributor, not a clone of ERPNext Purchase. Explicitly **not built**: RFQ, Purchase Request, Supplier Quotation, Approval Workflow, Landed Cost, Batch/Serial tracking, Returns, Payment Entry, Journal Entry, multi-currency purchasing. These are postponed until a confirmed business need justifies them — see `docs/DECISIONS.md`.

## Entities

- **PurchaseOrder** / **PurchaseOrderItem** — uses the `Documentable` trait (`documentType() = 'purchase'`). Does **not** touch inventory or accounting; it's purely a plan to buy. `PurchaseOrderItem.received_qty` tracks cumulative fulfillment as Goods Receipts are submitted against it.
- **GoodsReceipt** / **GoodsReceiptItem** — uses `Documentable` (`documentType() = 'goods_receipt'`). This is where physical receipt of goods is recorded, and the only place PO fulfillment turns into real stock and a real payable.
- **AccountsPayable** — plain entity (not `Documentable`), created automatically when a Goods Receipt is submitted. Has its own lifecycle (`Unpaid`/`PartiallyPaid`/`Paid`) unrelated to `DocumentStatus`.

## The workflow, step by step

1. **Create a Purchase Order** against a `Supplier`, with one or more lines (`item_id`, `qty`, `rate`). Status `Draft`. `total_amount` is computed server-side from the lines — never trust a client-sent total.
2. **Submit the PO** (`POST /purchase-orders/{id}/submit`). Requires at least one line. Status → `Submitted`. **No inventory or accounting effect** — this is intentional, matching "Purchase Order does NOT affect inventory."
3. **Create a Goods Receipt** against the submitted PO (`POST /goods-receipts`), specifying a `warehouse_id`, `receipt_date`, `due_date` (manually entered — see Known Limitations), and lines referencing `purchase_order_item_id` + the qty actually received. Rejected if the PO isn't Submitted, or if any line's qty exceeds that PO line's outstanding qty (`qty - received_qty`). Each line snapshots `item_code`/`item_name`/`uom`/`rate` from the PO item at this moment — it does not read live `Item` data later.
4. **Submit the Goods Receipt** (`POST /goods-receipts/{id}/submit`). Inside one `DB::transaction()`:
   - Re-validates the PO is still Submitted and quantities are still within the outstanding amount (state could have changed since step 3).
   - For every line, calls `StockLedgerService::record()` — the **only** thing that moves stock. `GoodsReceiptService` never writes to `Item.current_stock` or `stock_ledgers` directly.
   - Advances `PurchaseOrderItem.received_qty` for each line.
   - Flips status to `Submitted` via the `Documentable` trait (`$goodsReceipt->submit()`), which also records the `DocumentTimeline` entry.
   - Creates the `AccountsPayable` record: `amount` = sum of receipt line amounts, `reference_number` = the Goods Receipt's `document_number`, `due_date` copied from the Goods Receipt, `status` = `Unpaid`.
5. **Accounts Payable now exists**, read-only, visible at `GET /accounts-payables`. Nothing in this sprint changes its status — that's the Payment module's job, later.

## What's deliberately guarded

- **Goods Receipt cannot be cancelled.** `GoodsReceipt::cancel()` is overridden to unconditionally throw `BusinessException`, and no `POST /goods-receipts/{id}/cancel` route exists. A submitted receipt has already moved stock and created a payable; reversing that correctly is a Return workflow (compensating stock-out + payable adjustment), which does not exist yet. Leaving `cancel()` callable would let a status flip silently desynchronize inventory from reality — worse than not having the feature.
- **Purchase Order cancel is guarded**, not forbidden: rejected if any line already has `received_qty > 0`, since a PO with real receipts against it has already had downstream effects.
- **Update/delete on PurchaseOrder and GoodsReceipt are Draft-only.** Once submitted, a document is a historical record.
- **Over-receipt is rejected** at both create and submit time (defense in depth — state can change between the two).
- All business-rule violations above throw `App\Exceptions\BusinessException`, which renders as `{success:false, message, data:null}` at HTTP 422 — the same envelope as every other API response, unlike Laravel's default validation-exception shape.

## Manual testing steps

1. Ensure Master Data exists: at least one `Supplier`, one `Warehouse`, one `Item` (with `item_group_id`/`uom_id`).
2. `POST /api/v1/purchase-orders` with a supplier and one line — confirm `document_number` starts with `PO-` and `status` is `draft`.
3. `POST /api/v1/purchase-orders/{id}/submit` — confirm `status` becomes `submitted`.
4. `POST /api/v1/goods-receipts` referencing that PO's `purchase_order_item_id`, with `qty` less than what was ordered (to test partial receipt) and `due_date` ≥ `receipt_date`.
5. `POST /api/v1/goods-receipts/{id}/submit` — confirm response `status` is `submitted`.
6. `GET /api/v1/items/{item}/stock-ledger` — confirm a new entry with `voucher_type: goods_receipt` and the qty received.
7. `GET /api/v1/items/{item}` — confirm `current_stock` reflects the received qty.
8. `GET /api/v1/purchase-orders/{id}` — confirm the line's `received_qty`/`outstanding_qty` updated, and `is_fully_received` is `false` (partial receipt).
9. `GET /api/v1/accounts-payables?status=unpaid` — confirm a row with `reference_number` matching the Goods Receipt's `document_number` and `amount` matching qty × rate.
10. Repeat steps 4–5 receiving the remaining outstanding qty — confirm `is_fully_received` becomes `true` on the PO.
11. Try `POST /api/v1/goods-receipts` again with qty exceeding what's left outstanding — expect 422 `BusinessException`.
12. Try to cancel the (now submitted) Goods Receipt — there is no route for it; calling `$goodsReceipt->cancel()` anywhere in code throws.
13. Try `POST /api/v1/purchase-orders/{id}/cancel` on the PO used above — expect 422, since it already has receipts.

## Known limitations

- **No payment terms.** `due_date` is manually entered per Goods Receipt. No `payment_term_days` on Supplier, no Net-30 default — deliberately, per project philosophy of not inventing unconfirmed business rules. See `docs/DECISIONS.md`.
- **Single PO per Goods Receipt.** No consolidated receipt across multiple purchase orders.
- **No partial-payment tracking yet.** `AccountsPayable.paid_amount`/`PartiallyPaid`/`Paid` exist in the schema and enum but nothing updates them — that's the Payment Entry sprint.
- **No Return workflow**, so a wrongly-submitted Goods Receipt cannot be corrected through the API — only worked around manually at the database level (not recommended) until Returns are built.
- **No tax on Purchase Order lines.** The `Tax` master (Sprint 2) is not wired into pricing yet; PO/GR amounts are plain `qty × rate`.
- Real MySQL still hasn't been migrated in this environment (`MySQL80` service stopped, needs admin rights) — every sprint so far has been verified against a throwaway SQLite database. See `docs/DECISIONS.md#d-r09`.

## Next recommended sprint

**Sales Workflow** (Customer → Sales Order → Delivery → Stock Ledger(−) → Accounts Receivable), mirroring this sprint's shape exactly — it reuses the same Document Engine, the same `StockLedgerService` (now exercising the `OUT` transaction type for the first time), and the same "simple AP/AR, no payment module yet" scoping. Building Sales next (rather than jumping to Payment Entry or Returns) keeps the core buy/sell loop complete before layering financial or correction workflows on top of it.
