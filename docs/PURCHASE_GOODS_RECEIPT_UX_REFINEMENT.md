# Purchase & Goods Receipt — UX Refinement

Phase 2C.1. No new pages, no new backend endpoints, no data-model changes. Every change here surfaces information that already existed in the API responses but wasn't shown, or renames UI copy to match real operational language — nothing here required a new fetch beyond what the pages already had, except one client-side lookup (Related Goods Receipts on the PO Detail page) using an endpoint that already existed.

---

## 1. UX Improvements Made

| Page | Before | After |
|---|---|---|
| Purchase Order List | Document Number / Supplier / Date / Status / Total Amount only — no way to tell what's been received without opening every order | + **Receiving Progress** column: `waiting`/`partial`/`completed` badge, `received / ordered` numbers (always visible), and a progress bar |
| Purchase Order Detail | `Received` was the only receiving-related number, buried in the line-items table | + prominent **Receiving Progress** card right under the header; line items table gained **Remaining Qty** alongside Ordered/Received — all three visible together, no scrolling to piece them together |
| Purchase Order Detail | No way to see which Goods Receipts came from this order | + **Goods Receipts** card: every linked receipt with Document Number (linked), Receipt Date, Warehouse, Status, Qty Received |
| Goods Receipt List | Document Number / Purchase Order / Supplier / Receipt Date / Status | + **Warehouse** and **Qty Received** columns; **Purchase Order** cell is now a clickable link (was plain text) |
| Goods Receipt Editor/Detail | Buttons read "Save Draft" / "Submit" (generic ERP-document language) | Buttons read **"Receive Goods"** / **"Confirm Receipt"** (matches the physical operation); a one-line hint explains what each step actually does |

---

## 2. Screenshots (Before → After)

- Purchase Order List: `screenshot-1784268624053-21.jpg` → `screenshot-1784269248815-24.jpg`
- Purchase Order Detail: `screenshot-1784268640877-22.jpg` → `screenshot-1784269279423-25.jpg`
- Goods Receipt List: `screenshot-1784268666233-23.jpg` → `screenshot-1784269299563-26.jpg`
- Goods Receipt Editor (renamed "Receive Goods" button + hint text): `screenshot-1784269360472-27.jpg`

The "after" Purchase Order Detail screenshot also incidentally explains a data mystery from earlier testing — PO-00006 showed `received=17` with only one Goods Receipt remembered; the new "Goods Receipts" card reveals there were actually **two** (GR-00003 for 12, GR-00005 for 5, `12+5=17`), correctly summed and both linked. This is exactly the kind of question ("what actually happened to this order?") this refinement sprint exists to answer without spelunking.

---

## 3. Lifecycle Decision — Goods Receipt Draft Status

**Decision: keep Draft. No backend change.** Renamed the UI language instead of removing the step.

**Evaluation:** the sprint's own "preferred workflow" —

```
Purchase Order → Receive Goods → Confirm → Goods Receipt Created → Stock Updated
```

— already describes a **two-step** process. Mapped onto the existing implementation:

- **"Receive Goods"** = creating the Goods Receipt record (`POST /goods-receipts`, status `draft`). This is where quantities are captured — a real database row now exists, satisfying "Goods Receipt Created."
- **"Confirm"** = `POST /goods-receipts/{id}/submit`. This is where `StockLedgerService` posts the stock movement, the Purchase Order's `received_qty` advances, and `AccountsPayableService::createFromGoodsReceipt()` runs — satisfying "Stock Updated."

The sprint's preferred workflow **is** the draft → submit lifecycle already built; it was never a mismatch, only a vocabulary mismatch. Removing Draft — e.g. collapsing create+submit into one atomic call — would mean:

- Losing the review step between "what I typed" and "what actually moves stock and creates a payable" — a real safety property, not incidental complexity. A quantity typo caught before Confirm is a two-click fix; caught after, it requires a correction workflow that doesn't exist yet (no reversal — see below).
- Removing working `update()`/`delete()` functionality (both explicitly `assertDraft()`-gated) and the Edit/Delete actions built on them — a backend and feature regression, not a simplification.
- Breaking symmetry with Purchase Order's own draft → submit lifecycle, which this module was explicitly built to match (`docs/FRONTEND_GOODS_RECEIPT_WORKFLOW.md` §1).

**What was minimized instead (per the sprint's own fallback instruction — "minimize its visibility... and document the reason"):**

- Editor buttons: **"Save Draft" → "Receive Goods"**, **"Submit" → "Confirm Receipt"** (Editor, Detail, and List row actions — all three surfaces).
- Toasts renamed to describe the *effect*, not the ERP mechanism: *"Goods received. Confirm to update stock and create the payable."* → *"Receipt confirmed — stock updated."*
- A one-line hint now sits directly above the action buttons in the Editor, stating in plain language what each button does — so a user never needs to know the words "draft" or "submit" to use the page correctly.
- The `StatusBadge` itself still reads **"Draft"** / **"Submitted"** — deliberately not renamed. It's the one place showing raw, true document state (useful for support/debugging and for anyone who *does* think in ERP terms), while every button and message around it now speaks in receiving language. Renaming the badge to match would have created a second vocabulary for the same underlying enum value, which is confusion, not simplification.

**One thing this evaluation surfaced but did not act on:** Goods Receipt has no cancel/reversal path once confirmed (`GoodsReceipt::cancel()` unconditionally throws — "Reversal is only available through the Return workflow, not yet implemented"). That's a real gap in what happens *after* a mistaken confirmation, but building a Return workflow is a new feature, explicitly out of scope this sprint ("This phase is NOT about adding new features").

---

## 4. Purchase List Improvements

Added **Receiving Progress** (Part 1 & 2 combined into one column, deliberately — see §6): a `StatusBadge` (`waiting`/`partial`/`completed`), the `received / ordered` numbers (never hidden behind the badge or bar — both sprint requirements: "numbers must always remain visible" and "display both clearly without confusion"), and a `ProgressBar`. Draft and cancelled orders show a plain `—` rather than a fabricated status, since receiving is structurally impossible for either (a draft PO has nothing to receive against yet; a cancelled PO is guaranteed to have zero receipts — `PurchaseOrderService::cancel()` blocks cancellation once any exist).

No new API call — `PurchaseOrderRepository::search()` already eager-loads `items` for every row in the list response (added in Phase 2C for the total-amount column), so `received_qty`/`qty` per line and `is_fully_received` were already present in the payload, just unused by the frontend until now.

## 5. Goods Receipt List Improvements

Added **Warehouse** (already nested in `GoodsReceiptResource`, zero extra cost) and **Qty Received** (summed client-side from the already-loaded `items` array — same "no new fetch" situation as the PO List). Made the **Purchase Order** cell a real link (`stopPropagation` so it doesn't also trigger the row's own click-to-detail navigation) instead of plain text, directly serving Part 7's "reduce unnecessary clicks."

The list, as of this sprint, answers "what has already been received today?" in one glance: Document Number, Purchase Order (linked), Supplier, Warehouse, Receipt Date, Qty Received, Status — filterable by date range and status, sortable by date — with zero Detail-page visits required.

---

## 6. Navigation Improvements

- **Purchase Order → Goods Receipt(s):** new "Goods Receipts" card on the PO Detail page, listing every receipt raised against that order (client-filtered from `GET /goods-receipts?per_page=100` by `purchase_order_id` — no backend filter parameter added, consistent with this sprint's no-new-features scope; same lookup-list ceiling already documented for other cross-references in this codebase). Only fetched when the PO is `submitted`, since that's the only status that can ever have receipts.
- **Goods Receipt → Purchase Order:** already existed (`View Purchase Order` button on GR Detail, built in Phase 2D) — now supplemented by the List page's clickable Purchase Order column, so the same jump is available without opening the Detail page first.
- Both directions now reachable in exactly one click from either List or Detail — the sprint's literal "make document flow obvious" goal.

---

## 7. Design Rationale — Why One Column, Not Two

Part 1 asked for a "Receiving Progress" column (numbers, optionally a bar); Part 2 asked for a derived receiving status shown via `StatusBadge`, with the explicit caveat "display both clearly without confusion." These were built as **one combined column** (badge + numbers + bar, top to bottom) rather than two separate columns, because:

- The lifecycle `Status` column (draft/submitted/cancelled) already exists and is untouched — that's the "clearly separated" half of "without confusion": receiving status is never in the same column as lifecycle status, so the two concepts can't be mistaken for each other.
- A third bare column of just the receiving badge, next to a fourth column of just the numbers, would have split one coherent idea ("how much of this order has arrived") across two columns for no navigational benefit — worse density for no added clarity, working against the ERP "dense but readable" principle this whole project has followed since Sprint 1.
- `ReceivingProgress` (`features/purchase/components/ReceivingProgress.tsx`) is reused verbatim between the List (`size="sm"`) and the Detail page's prominent summary (`size="lg"`) — one component, one place the badge/number/bar relationship is defined, not two independent implementations that could drift.

---

## 8. Recommendation for the Next Module

This sprint didn't add a page, so the standing recommendation from Phase 2D still holds: **Payment Entry** is the natural next module — every Goods Receipt submission already creates a real, unpaid Accounts Payable record (`AccountsPayableService::createFromGoodsReceipt()`), so there's genuine data waiting the moment Payment Entry exists, the same way this sprint found genuine (if previously invisible) receiving data waiting in Purchase Order.

One thing worth carrying into that module directly from this sprint's experience: **build the List page's "what's outstanding" view from day one**, not as a follow-up refinement — an Accounts Payable list that only shows amount/status without surfacing "how much of this is overdue" or "how much has been paid so far" would recreate exactly the gap this sprint just closed for Purchase Order. The `ReceivingProgress`-style pattern (badge + numbers + bar, one reusable component, numbers always visible) generalizes directly to "Payment Progress" (`paid / total`) if useful.
