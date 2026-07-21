# Payment Workflow (Sprint 6)

Financial settlement only — not accounting. Closes the loop opened by Sprint 4 (`AccountsPayable`) and Sprint 5 (`AccountsReceivable`): both entities existed with real data since their respective sprints, but nothing updated `paid_amount`/`status` until now.

```
Supplier Payment:  AccountsPayable    → Payment Entry → paid_amount → status
Customer Receipt:  AccountsReceivable → Receipt Entry → paid_amount → status
```

## Scope

Explicitly **not built**: Journal Entry, General Ledger, Chart of Accounts, Bank Reconciliation, multi-currency, exchange rate, tax posting, payment allocation rules, accounting automation. This sprint only makes `AccountsPayable`/`AccountsReceivable`'s existing `paid_amount`/`status` columns (defined since Sprint 4/5, unused until now) actually mean something.

## Entities

- **PaymentEntry** / **PaymentEntryItem** — uses `Documentable` (`documentType() = 'payment'`, new `PAY-` `NamingSeries`, `module: finance`). One entry can settle multiple `AccountsPayable` rows across possibly-different Purchase Orders/Goods Receipts, as long as they're all owed to the same `supplier_id`.
- **ReceiptEntry** / **ReceiptEntryItem** — mirror of the above for customers (`documentType() = 'receipt'`, `REC-` series). Settles `AccountsReceivable`.

Both headers carry `payment_method` (`cash`\|`bank_transfer`\|`cheque` — a new closed-set `PaymentMethod` enum, reused by both sides rather than defined twice) and a **user-entered** `reference_number` (an external payment reference — a bank transfer ID, a cheque number). This is a different thing from `AccountsPayable.reference_number`/`AccountsReceivable.reference_number`, which are system-generated snapshots of the originating Goods Receipt/Delivery's `document_number` — same column name, different origin, worth not confusing.

## What's reused, unchanged, from Sprint 0–5

- `Documentable` — numbering, Draft/Submitted/Cancelled lifecycle, `DocumentTimeline`, hooks. Neither `PaymentEntry` nor `ReceiptEntry` override `afterCreate`/`afterSubmit`/`afterCancel` — the settlement side effect is orchestrated in the Service (`PaymentEntryService::submit()`/`ReceiptEntryService::submit()`), consistent with how `GoodsReceiptService`/`DeliveryService` keep business logic in the Service layer rather than the Model, per `02_SYSTEM_ARCHITECTURE.md`.
- `BusinessException`, `BaseRepository`, `ApiResponse`, the full Controller → Service → Repository → Model layering.
- The "validate every line, then mutate every line, then flip status" shape from `GoodsReceiptService`/`DeliveryService::submit()` — copied structurally because it's the same problem shape (a multi-line document whose submit both re-validates against live state and triggers external-record side effects), not because it was blindly pasted.

## What's new

- **`PaymentMethod` enum** (`app/Enums/PaymentMethod.php`) — `Cash`, `BankTransfer`, `Cheque`. Shared by both `PaymentEntry` and `ReceiptEntry`; a payment method isn't a payable-specific or receivable-specific concept.
- **`App\Support\SettlementStatus::resolve(amount, paidAmount): string`** — a small pure helper (not a Model, Service, or Repository — a plain static function) that returns `'unpaid'`/`'partially_paid'`/`'paid'` from the two numbers. Both `AccountsPayableService::settle()` and `AccountsReceivableService::settle()` call it and cast the result to their own enum (`AccountsPayableStatus::from(...)` / `AccountsReceivableStatus::from(...)`). One calculation, not two — see `docs/DECISIONS.md`.
- **`AccountsPayableService::settle()`** / **`AccountsReceivableService::settle()`** — new methods (the services themselves existed since Sprint 4/5 as read-only + creation-from-workflow). Each increments `paid_amount` by the settlement amount, recomputes `status` via `SettlementStatus`, and persists both in one update.
- **Duplicate-reference guard** — a `PaymentEntry`/`ReceiptEntry` cannot reference the same `AccountsPayable`/`AccountsReceivable` twice across its own items. Not requested explicitly, added because it's a correctness issue: without it, two lines against the same payable, each individually valid against its outstanding balance at input time, could jointly overpay it — the per-line check has no way to see the other pending line in the same request.

## The workflow, step by step (Supplier Payment; Customer Receipt is identical, AP↔AR/Payment↔Receipt)

1. **Create a Payment Entry** against a `Supplier`, with one or more lines (`accounts_payable_id`, `paid_amount`). Each line is validated immediately: `paid_amount > 0`, `paid_amount <= (AP.amount - AP.paid_amount)`, the AP belongs to the given supplier, no AP repeated across lines. Status `Draft`. `total_amount` computed server-side.
2. **Submit the Payment Entry** (`POST /payment-entries/{id}/submit`). Inside one `DB::transaction()`:
   - Re-validates every line against each AP's **current** outstanding (it may have shrunk since step 1, if another Payment Entry settled it in the meantime).
   - For every line, calls `AccountsPayableService::settle()` — the only thing that changes `AccountsPayable.paid_amount`/`status`.
   - Flips status to `Submitted` via `Documentable`, recording the `DocumentTimeline` entry.
3. **The Accounts Payable rows are now updated** — `GET /accounts-payables/{id}` shows the new `paid_amount`, `outstanding_amount`, and `status` (`unpaid` → `partially_paid` once any payment lands, → `paid` once `paid_amount` reaches `amount`).

Nothing about steps 1–3 differs for Receipt Entry beyond swapping Supplier→Customer, AccountsPayable→AccountsReceivable, `paid_amount`→`received_amount`.

## What's deliberately guarded

- **Neither Payment Entry nor Receipt Entry can be cancelled.** Both override `cancel()` to unconditionally throw `BusinessException`; no cancel route exists for either. A submitted settlement has already reduced a real balance — reversing it correctly needs a dedicated void/undo workflow that doesn't exist yet. This was not explicitly requested this sprint, but is the same rationale already established (and approved) for `GoodsReceipt`/`Delivery` in Sprint 4/5: a financial/inventory side effect with no reversal mechanism should never be flip-able back to Draft by a bare status change.
- **Over-payment is rejected** at both create and submit time, against the AP/AR's live outstanding balance — mirrors the over-receipt/over-delivery guards exactly.
- **`paid_amount`/`received_amount` must be `> 0`** — enforced in the FormRequest (`gt:0`) and again in the Service (defense in depth, same double-check pattern as every other Sprint 4/5 guard).
- **Duplicate AP/AR reference within one entry is rejected** — see "What's new" above.
- **Update/delete are Draft-only.**
- All violations throw `BusinessException` → `{success:false, message, data:null}` at HTTP 422.

## Manual testing steps

Requires an `AccountsPayable` and an `AccountsReceivable` already in `unpaid` status — run the Purchase Workflow (Sprint 4) and Sales Workflow (Sprint 5) first.

1. `GET /api/v1/accounts-payables?status=unpaid` — note an `id` and its `amount`.
2. `POST /api/v1/payment-entries` with that `accounts_payable_id` and a `paid_amount` **less than** the full amount (partial payment). Confirm `document_number` starts with `PAY-`, `status` is `draft`.
3. `POST /api/v1/payment-entries/{id}/submit` — confirm `status` becomes `submitted`.
4. `GET /api/v1/accounts-payables/{id}` — confirm `paid_amount` increased, `status` is now `partially_paid`, `outstanding_amount` shrank accordingly.
5. `POST /api/v1/payment-entries` again against the same AP, with `paid_amount` equal to the remaining outstanding, then submit it.
6. `GET /api/v1/accounts-payables/{id}` — confirm `status` is now `paid`, `outstanding_amount` is `0`.
7. Try a third `POST /api/v1/payment-entries` against the now-fully-paid AP with any `paid_amount` — expect 422 ("exceeds outstanding payable ... 0").
8. Try a `POST /api/v1/payment-entries` with the same `accounts_payable_id` listed twice in `items` — expect 422 (duplicate reference).
9. Confirm there is no cancel route for Payment Entry; `$paymentEntry->cancel()` throws if called anywhere in code.
10. Repeat steps 1–9 for `GET /api/v1/accounts-receivables` / `POST /api/v1/receipt-entries` — identical behavior on the receivable side.

## Known limitations

- **No accounting.** Settling an AP/AR here does not create a Journal Entry, does not touch a General Ledger (neither exists), and produces no double-entry bookkeeping record. This sprint is explicitly scoped to stop there.
- **No bank reconciliation.** `payment_method`/`reference_number` are recorded as plain input, not matched against any bank statement or external system.
- **No multi-currency / exchange rate handling** on settlement — amounts are settled in whatever unit the AP/AR was already denominated in (the `Currency` master from Sprint 2 remains unwired into any transaction, same as Sprint 4/5).
- **No payment allocation rules** (e.g. FIFO auto-apply against a customer's oldest outstanding receivables) — every settlement line is a manual, explicit `accounts_payable_id`/`accounts_receivable_id` choice.
- **No void/undo.** Once a Payment or Receipt Entry is submitted, its effect on the AP/AR is permanent until a future Returns/Void sprint exists.

## Recommended next sprint

With Purchase, Sales, and now Settlement all functioning end-to-end, the natural next step is **Reporting** (Outstanding Payables/Receivables aging, Stock Ledger summary, simple Profit view from PO/SO rate deltas) — a read-only sprint that adds real business value without touching any write-path guard rails, and gives the four sprints of transactional data built so far somewhere to be seen in aggregate. Journal Entry / General Ledger remain postponed until there's a confirmed need for real double-entry accounting rather than the settlement-only tracking this sprint provides.
