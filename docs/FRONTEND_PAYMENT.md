# Frontend — Payment Module (Finance: Incoming / Outgoing Payment)

Sprint 9. Payment is purely financial: it settles outstanding balances created by Purchase (via Goods Receipt) and Sales (via Delivery) without ever touching stock. Stock only ever changes through Goods Receipt, Delivery, and Stock Adjustment — Payment/Receipt Entry never call `StockLedgerService` or touch `Item.current_stock` anywhere, confirmed structurally (no such call exists in either service) and confirmed at runtime (the stock ledger's `voucher_type` distribution after this sprint's full test cycle contains only `goods_receipt`/`delivery`/`stock_adjustment` — zero `payment`/`receipt` entries).

**The backend for this sprint was already ~90% built**, from an earlier "Sprint 6: Financial Settlement" that predates this session's frontend work — `AccountsPayable`/`AccountsReceivable` (system-generated, tracking `amount`/`paid_amount`/`status`) and `PaymentEntry`/`ReceiptEntry` (full Documentable draft→submit documents, `PAY-`/`REC-` naming series already seeded) all existed, migrated, and routed before this sprint started. This sprint's work was: fill four small additive backend gaps, then build the entire frontend.

---

## 1. Analysis — answering the sprint's design questions before implementation

### One table or two?

Already answered by the existing schema: `payment_entries` and `receipt_entries` are two separate tables, mirroring `accounts_payables`/`accounts_receivables` — themselves already two separate tables. This mirrors every other domain pair in this app (Purchase/Sales, GoodsReceipt/Delivery are always separate tables even when structurally near-identical). Two tables keeps each side's foreign-key constraints simple (`payment_entry_items.accounts_payable_id` restrict-deletes against `accounts_payables`, never needing a nullable "either/or" column) and matches the established convention — this wasn't a decision this sprint needed to make, just one to recognize and follow.

### Relation to Purchase and Sales

Indirect, and deliberately so. `PaymentEntry`/`ReceiptEntry` never reference `PurchaseOrder`/`SalesOrder` (or `GoodsReceipt`/`Delivery`) directly anywhere in their own schema — `payment_entry_items.accounts_payable_id` / `receipt_entry_items.accounts_receivable_id` are the *only* foreign keys either table has. `AccountsPayable`/`AccountsReceivable` sit as the abstraction boundary between "a transaction that owes money" (Payment's concern) and "whichever document currently proves it's owed" (Purchase/Sales' concern today).

### What changes when Invoice is added later

Nothing in Payment's own schema, service, controller, validation, or settlement math (`AccountsPayableService::settle()`, `SettlementStatus::resolve()`, the outstanding/status auto-calculation) — all of that already only knows about `AccountsPayable`/`AccountsReceivable` rows, never about what created them. Introducing Invoice would only touch the *creation side*: `AccountsPayableService::createFromGoodsReceipt()`/`AccountsReceivableService::createFromDelivery()` would become `createFromInvoice()`-shaped, and the `purchase_order_id`/`goods_receipt_id` columns on `AccountsPayable` would be joined by (or replaced by) an `invoice_id` column. On the frontend, the only place that currently assumes "Purchase/Sales" is `resolveSourceDocumentLink()` (`features/payment/lib/sourceDocumentLink.ts`) and the client-side lookup-joins in the List/Editor pages that resolve a PO/SO document number for display — swapping those to resolve an Invoice number instead is a small, contained change, not a rewrite of the Payment module itself. This is the concrete reason the current design is already "easy to swap to Invoice" without a big refactor, per the sprint's explicit requirement.

---

## 2. Backend — four small additive gaps, filled before any frontend code

Found by reading the existing `PaymentEntryController`/`ReceiptEntryController`/`AccountsPayableController`/`AccountsReceivableController` against the sprint spec and this app's established conventions; all curl-verified before frontend work began.

1. **`PaymentEntryController::index()` / `ReceiptEntryController::index()` took zero filters** — no search/status/date/entity param support at all, unlike every other transactional list in this app. Added `IndexPaymentEntryRequest`/`IndexReceiptEntryRequest` (search, status, supplier_id/customer_id, date_from/date_to, per_page) plus `PaymentEntryRepository::search()`/`ReceiptEntryRepository::search()`, mirroring `GoodsReceiptRepository::search()` exactly.
2. **`AccountsPayableController`/`AccountsReceivableController::index()` silently ignored `per_page`** — always capped at 15 regardless of the query param. Added the `per_page` validation rule and wired it through to the already-`$perPage`-accepting service methods. This mattered specifically for the Editor's outstanding-document picker, which needs every outstanding row for a supplier/customer, not just the first 15.
3. **`App\Enums\PaymentMethod` had only 3 cases** (`cash`, `bank_transfer`, `cheque`) — the spec requires 5 (Cash, Bank Transfer, QRIS, Credit Card, Giro/Cheque). Added `QRIS` and `CREDIT_CARD` — plain string column, no migration needed.
4. **`AccountsPayableResource`/`AccountsReceivableResource` expose `purchase_order_id`/`sales_order_id` as raw IDs, not nested objects** — so "Source Document" display (e.g. "SO-00015") is resolved via the same client-side lookup-join pattern already used in `GoodsReceiptListPage.tsx`/`DeliveryListPage.tsx`, not a backend resource change.

No new tables, no new columns beyond the additive filter/enum work above, no changes to `PaymentEntryService`/`ReceiptEntryService`'s `create`/`update`/`submit`/settlement logic — already correct and already handled auto-calculation, outstanding validation, and partial-payment sequencing exactly as the spec requires.

---

## 3. Scope decision: one source document per payment

`PaymentEntryService`'s `items[]` array already supports settling *multiple* Accounts Payable rows in a single Payment Entry (a real, tested backend capability). The sprint spec's every example, business rule, and validation description assumes exactly one source document per payment ("Source: SO-00015, Amount: 5.000.000" — singular) and never mentions split/multi-line payments. The frontend Editor always builds a one-element `items` array — full use of the existing, unchanged backend contract, without building an unrequested multi-line UI. If multi-document payments are ever needed, the backend already supports it; only the Editor's picker would need to become a field array.

---

## 4. Frontend `features/payment/`

```
features/payment/
  types.ts             PaymentEntry/PaymentEntryItem/AccountsPayable + ReceiptEntry/ReceiptEntryItem/AccountsReceivable
                        (duplicated shape, matching this codebase's per-feature-duplication convention for DocumentStatus)
  navigation.ts         financeSectionNav — Incoming Payment (/finance/incoming), Outgoing Payment (/finance/outgoing)
  lib/
    paymentEntryFilters.ts, receiptEntryFilters.ts    empty/hasActive helpers (status + date range)
    paymentEntryFormSchema.ts, receiptEntryFormSchema.ts   zod — amount refined against a client-snapshotted
                                                             outstandingAmount, same shape as goodsReceiptFormSchema's `remaining`
    paymentMethodLabels.ts   PAYMENT_METHOD_LABELS map + PAYMENT_METHOD_OPTIONS (Cash/Bank Transfer/QRIS/Credit Card/Giro-Cheque)
    sourceDocumentLink.ts    resolveSourceDocumentLink(kind, id) — same shape as inventory/lib/voucherLinks.ts
  api/
    paymentEntryApi.ts, receiptEntryApi.ts    full CRUD + submit, mirrors deliveryApi.ts — no cancel function (no route)
    accountsPayableApi.ts, accountsReceivableApi.ts   read-only, used only by the Editor's picker
  components/
    PaymentEntryFiltersBar.tsx, ReceiptEntryFiltersBar.tsx   mirror GoodsReceiptFiltersBar (Status + date range)
    OutstandingPayableSelect.tsx, OutstandingReceivableSelect.tsx   the Source Document picker (see §5)
  pages/
    IncomingPaymentListPage.tsx / IncomingPaymentEditorPage.tsx / IncomingPaymentDetailPage.tsx
    OutgoingPaymentListPage.tsx / OutgoingPaymentEditorPage.tsx / OutgoingPaymentDetailPage.tsx
```

Six pages, no shared generic component — Incoming/Outgoing differ enough in field names (`supplier`/`customer`, `payment_date`/`receipt_date`, `paid_amount`/`received_amount`) that forcing a shared abstraction would fight this codebase's established "duplicate per feature" convention (the same reasoning that already keeps AP/AR and GR/Delivery as separate types).

Routes (`app/router.tsx`): `/finance/incoming`, `/finance/incoming/new`, `/finance/incoming/:id/edit`, `/finance/incoming/:id`, and the `/finance/outgoing` equivalents. The sidebar's dead "Payment" placeholder (`/payment`, falling through the router's catch-all to `/dashboard`) is renamed to "Finance" and now points at `/finance/outgoing`.

---

## 5. The Source Document picker

`OutstandingPayableSelect`/`OutstandingReceivableSelect` are the mechanism that enforces "Payment cannot be created without a Source Document" at the UI level: the picker is disabled until a supplier/customer is chosen, and it only ever lists that entity's outstanding (non-`paid`) Accounts Payable/Receivable rows — there is no way to reach the Amount field without first picking a real, outstanding transaction.

- Fetches `GET /accounts-payables?supplier_id=...&per_page=100` (or the receivable equivalent), then filters client-side to `status !== 'paid'`. `IndexAccountsPayableRequest.status` only matches one exact enum value — expressing "not paid" (unpaid OR partially_paid) would need an `OR`, which isn't worth a second backend filter shape for one dropdown; same pattern already used for `GoodsReceiptEditorPage`'s `!po.is_fully_received` filter.
- Runs the same purchase-orders/sales-orders lookup-join query the List pages use (`queryKey: ['purchase-orders-lookup']` / `['sales-orders-lookup']`) to label each option `PO-00001 — Outstanding: Rp X` — React Query dedupes the request across the List and Editor pages for free, since it's the same key.
- Selecting an option defaults the Amount field to that row's full outstanding amount (editable down for a partial payment) and populates a read-only Purchase/Sales Summary card (Grand Total / Paid / Outstanding / Payment Status) directly from the picked row — no `useWatch`/`form.watch()` needed for this, since the picker's `onChange` callback hands back the full `AccountsPayable`/`AccountsReceivable` object at the exact moment of selection, avoiding the RHF watch-reactivity class of bug this project has hit twice before in other editors.

---

## 6. Auto-calculation and validation

- **Paid Amount, Outstanding, and Payment Status are never user-entered anywhere** — they're always read directly from the linked Accounts Payable/Receivable, which the backend recomputes via `SettlementStatus::resolve()` inside `AccountsPayableService::settle()`/`AccountsReceivableService::settle()` at submit time. The frontend only ever displays these values.
- **Amount cannot exceed Outstanding**: enforced client-side (zod `superRefine` in `paymentEntryFormSchema.ts`/`receiptEntryFormSchema.ts`, comparing against the outstanding amount snapshotted when the source document was picked) and authoritatively server-side (`PaymentEntryService::assertWithinOutstanding()`, re-checked against the *current* balance at submit time — the client's snapshot could be stale if another payment against the same document was submitted in between).
- **Draft vs. submitted**: saving a Payment/Receipt only ever writes the document itself — `settle()` is not called until "Confirm Payment"/"Confirm Receipt" is pressed, matching every other document module's draft→submit lifecycle in this app. No cancel action exists (no route, no button) — `PaymentEntry::cancel()`/`ReceiptEntry::cancel()` always throw, same rationale as `GoodsReceipt::cancel()`/`Delivery::cancel()`: a submitted settlement has already reduced a real balance, and no reversal workflow exists yet.

---

## 7. List page "Status" column

The spec is explicit: "Status cukup: Partial, Paid" — never "Unpaid," because a transaction with zero payments simply doesn't appear as a *source document option* until it has one. This does not mean every row on the Payment List is guaranteed non-draft, so the column's logic is:

```
row.status === 'submitted'
  ? <StatusBadge status={row.items[0].accounts_payable.status} />   // Partially Paid / Paid
  : <StatusBadge status={row.status} />                              // Draft
```

A submitted row's linked Accounts Payable/Receivable is, by construction, never `unpaid` — `submit()` only ever calls `settle()` with a `paid_amount`/`received_amount` greater than zero (enforced by `StorePaymentEntryRequest`'s `gt:0` rule), so the resulting status is always `partially_paid` or `paid`. `StatusBadge`'s existing `STATUS_STYLES` map already has entries for all four values (`draft`, `submitted` mapped through to the document badge; `partially_paid`, `paid` already used by the AP/AR-derived badge) — no changes needed to the shared component.

---

## 8. Deliverables

**Pages completed**: `IncomingPaymentListPage`, `IncomingPaymentEditorPage`, `IncomingPaymentDetailPage`, `OutgoingPaymentListPage`, `OutgoingPaymentEditorPage`, `OutgoingPaymentDetailPage` — all reusing the established design system (`PageHeader`, `ActionBar`, `SearchBox`, `FilterPanel`, `DataTable`, `RowActionsMenu`, `Pagination`, `StatusBadge`, `DetailField`/`DetailSection`, `DeleteDialog`, RHF + Zod forms) with zero new shared components or styles.

**Backend changes**: 4 additive gaps filled (§2), all curl-verified, zero new tables/business-logic changes.

**Screenshots**:
- Outgoing Payment List (4 payments: Paid/Partially Paid/Partially Paid/Paid, Source Document links resolved): `screenshot-1784309347709-77.jpg`
- Outgoing Payment Detail, partial (PAY-00002 — Grand Total 500.000, Paid 250.000, Outstanding 250.000, "Partially Paid"): `screenshot-1784308733703-71.jpg`
- Outgoing Payment Detail, fully paid in one payment (PAY-00004 — 780.000/780.000, Outstanding 0, "Paid"): `screenshot-1784309325480-76.jpg`
- Outgoing Payment Editor, draft with picker + auto-filled Amount + Purchase Summary card: `screenshot-1784309280308-75.jpg`
- Incoming Payment List: `screenshot-1784309369505-78.jpg`
- Incoming Payment Detail, fully paid (REC-00002 — Sales Summary Grand Total 65.000, Paid 65.000, Outstanding 0, "Paid"): `screenshot-1784309939537-79.jpg`

**Verified end-to-end in-browser** (both Incoming and Outgoing): full partial-payment sequence (first payment → "Partially Paid" with correct remaining outstanding; second payment for the remainder → "Paid" with outstanding zeroed); the picker excluding already-paid documents from subsequent payment attempts; Source Document links navigating to the real Purchase Order/Sales Order detail pages; all 5 payment methods present in the selector; and — via a direct stock-ledger query after the full test cycle — zero ledger entries of `voucher_type` `payment`/`receipt`, confirming Payment never touches stock. `npx tsc -b` and `npx oxlint` both clean.
