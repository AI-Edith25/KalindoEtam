# Integration Checklist (Sprint 7)

Stabilization sprint: no new business modules. This document is the record of what was reviewed, what was found, what was fixed, and what's left — the frontend team's starting point for integration.

## Verified workflows

All six workflows were re-run end-to-end via smoke test after every fix in this sprint (not just re-read — actually exercised), including a scenario none of Sprint 4–6's own smoke tests covered: an item stocked across **two warehouses** simultaneously.

| Workflow | Status | Notes |
|---|---|---|
| Purchase Order (create → submit → cancel guard) | ✅ Verified | Draft-only update/delete, cancel rejected once received, unchanged since Sprint 4 |
| Goods Receipt (create → submit → stock +) | ✅ Verified | `cancel()` confirmed to always throw; over-receipt guard confirmed |
| Sales Order (create → submit → cancel guard) | ✅ Verified | Symmetric to Purchase Order, unchanged since Sprint 5 |
| Delivery (create → submit → stock −) | ✅ Verified | `cancel()` confirmed to always throw; over-delivery **and** insufficient-stock guards both confirmed |
| Payment Entry (settle AP, partial → full) | ✅ Verified | Status transition Unpaid → PartiallyPaid → Paid confirmed at each step; over-payment on a fully-paid AP rejected |
| Receipt Entry (settle AR) | ✅ Verified | Mirrors Payment Entry exactly |
| Multi-warehouse stock aggregation | ✅ Fixed & verified | Was broken since Sprint 1 — see below |
| Dashboard (all 7 endpoints) | ✅ Verified | Exercised against real data produced by the above workflows, not fixtures |

## Issues found and fixed this sprint

| # | Issue | Severity | Fix | Detail |
|---|---|---|---|---|
| 1 | `Item.current_stock` overwritten (not summed) across warehouses | **High** — silently wrong data, no error | `StockLedgerRepository::totalBalanceForItem()` sums per-warehouse latest balances | `docs/DECISIONS.md#d-r31` |
| 2 | Pagination `meta` (current_page/total/last_page) missing from **every** list endpoint | **High** — frontend cannot paginate any table without this | `ApiResponse::success()` now detects and attaches `meta` | `docs/DECISIONS.md#d-r32` |
| 3 | Dashboard date-filter queries silently matched nothing on SQLite | Medium — caught before shipping, would not have reproduced on MySQL | `where()` → `whereDate()` | `docs/DECISIONS.md#d-r33` |
| 4 | N+1: `item.uom` not eager-loaded when resolving a PO/SO line for a Goods Receipt/Delivery snapshot | Low — bounded by lines-per-request | Eager-load added to `PurchaseOrderItemRepository`/`SalesOrderItemRepository::findOrFail()` | `docs/DECISIONS.md#d-r35` |
| 5 | Seven dashboard-relevant columns had no index | Low today (small dataset), grows with data volume | New migration, 7 indexes, no behavior change | `docs/DECISIONS.md#d-r36` |

None of these required a schema redesign or touched business rules — all five are "the code didn't do what the documentation already claimed it did" or "the query wasn't portable," not new decisions.

## API consistency — audited, no fixes needed

- **Every** controller uses the `ApiResponse` trait (`grep -L` found zero exceptions across all 20+ controllers).
- **Every** `store()` action returns HTTP 201.
- **Every** workflow header Service (`PurchaseOrderService`, `GoodsReceiptService`, `SalesOrderService`, `DeliveryService`, `PaymentEntryService`, `ReceiptEntryService`) applies the identical `assertDraft()`-guarded update/delete + `submit()` + (where applicable) `cancel()` shape.
- **Every** business-rule violation across all six workflows throws `BusinessException`, rendering the same `{success:false, message, data:null}` envelope at 422 — confirmed by grep, not just by having written it that way.
- Every `show()` action's eager-load list was compared against its Repository's `index()` eager-load constant for all 9 relevant entities (Purchase Order, Goods Receipt, Sales Order, Delivery, Payment Entry, Receipt Entry, Accounts Payable, Accounts Receivable, Item) — **all matched**, no divergence found.

## Unresolved issues / known gaps (not fixed — out of this sprint's scope or deliberately deferred)

- **No authorization enforcement.** Every `FormRequest::authorize()` in the codebase returns `true` unconditionally, and Spatie permissions (seeded since Sprint 1) are never actually checked against the authenticated user anywhere. This is a carried-forward gap from Sprint 1, not introduced this sprint — flagged here because "frontend integration readiness" is exactly the point at which it starts to matter (a frontend building role-based UI would currently get zero enforcement from the backend). Recommend addressing before any multi-user/production deployment.
- **No authentication wired up.** No Sanctum/session guard is active; `Auth::id()` (used throughout `HasAuditTrail`, `DocumentAttachment.uploaded_by`, etc.) is always `null` in the current setup. Same carried-forward status as above.
- **Real MySQL still unverified.** Every sprint since Sprint 1 has been verified against a throwaway SQLite database because the local `MySQL80` service is stopped and starting it needs admin rights this environment doesn't have (`docs/DECISIONS.md#d-r09`). D-R33 (this sprint) is a concrete example of a bug that SQLite's behavior exposed and MySQL's stricter `DATE` type would have masked — meaning the reverse is also possible: a MySQL-specific issue could exist that SQLite testing cannot catch. **This is the single most important pre-production action item.**
- **`purchases-today`/`sales-today` are order-value, not receipt/delivery-actuals.** Documented as a scope decision in `docs/API.md`, not a bug — flagged here in case the frontend's actual need turns out to be the receipt-actuals variant.
- **`low-stock-items` threshold is caller-supplied, no persisted per-item minimum.** See `docs/DECISIONS.md#d-r34`.

## Technical debt (pre-existing, re-confirmed still present, not addressed this sprint per "fix only architecture or correctness issues")

- `Documentable::submit()`/`cancel()` (Sprint 3) use Laravel's `abort_if()`, producing Laravel's default error shape rather than `BusinessException`'s project-standard envelope — inconsistent with every guard added since Sprint 4. Noted in `docs/DECISIONS.md#d-r20` at the time, still unresolved. Low risk (both still return 422 with a `message`), but a frontend parsing error responses strictly by shape should know this one code path differs.
- `Company.currency` (Sprint 2) remains a plain string, never wired to the `Currency` master added in the same sprint.
- No Return/void workflow exists anywhere — `GoodsReceipt`, `Delivery`, `PaymentEntry`, `ReceiptEntry` all permanently forbid `cancel()` once submitted, by design, pending that future workflow.
- No accounting layer (Journal Entry, General Ledger) — explicitly out of scope for this project's MVP philosophy through Sprint 7.

## Frontend integration notes

- **Pagination**: read `meta.current_page`/`meta.last_page`/`meta.total` (now present, see fix #2) on every list view — don't assume `data.length` tells you anything about total records.
- **Error handling**: two 422 shapes exist in practice — `BusinessException`'s `{success:false, message, data:null}` (the standard, used by all workflow guards) and Laravel's default validation-error shape (`{message, errors: {field: [...]}}`) for FormRequest failures. Distinguish by checking for a `success` key; a validation error won't have one, matching Laravel's stock format rather than this project's envelope. Field-level FormRequest errors are needed for form UX (which field failed); `BusinessException` errors are single-message business-rule failures with no field to highlight.
- **Status enums are always plain lowercase-with-underscore strings** in JSON (`"partially_paid"`, not `"PartiallyPaid"`) — every `*Status`/`DocumentStatus`/`PaymentMethod` enum is `string`-backed and serializes as its `->value`.
- **`GoodsReceipt`/`Delivery`/`PaymentEntry`/`ReceiptEntry` never expose a cancel action** — don't build a cancel button for these four; only `PurchaseOrder`/`SalesOrder` have one (`POST .../cancel`), and even that is conditionally rejected once the order has any fulfillment against it.
- **Snapshot fields ≠ live master data.** `GoodsReceiptItem`/`DeliveryItem`'s `item_code`/`item_name`/`uom`(/`rate` for Delivery) reflect what was true at transaction time, not the current `Item` record — a renamed Item won't retroactively change historical receipts/deliveries. Don't "fix" a mismatch you notice between a historical document and current master data; it's intentional.
- **Dashboard endpoints are individually cheap**, not one big page-load call — call only the widgets actually rendered, poll independently if needed.
