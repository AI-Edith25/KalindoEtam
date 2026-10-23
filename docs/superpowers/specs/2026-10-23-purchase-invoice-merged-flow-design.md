# Purchase Invoice: Merge "From Goods Receipt" + "Direct/Non-Stock" Flows

## Context

Purchase > Invoices > New Invoice currently forces a choice up front between two
mutually exclusive creation modes:

- **From Goods Receipt** — pick one or more submitted GRs from one supplier; line
  items come from GR items (qty/item code locked, rate editable).
- **Direct / Non-Stock** — no GR; user manually adds rows with a Chart-of-Accounts
  `Account` dropdown, `Description`, `Qty`, `Rate`.

Real supplier invoices often mix both: stock items plus a non-stock deduction line
(e.g. "PPH 22" tax withholding) on the same document. The current UI can't express
that. This spec merges the two flows into one, with GR selection becoming optional
and non-stock lines addable regardless of whether any GR was selected.

This feature is **not greenfield**. The dual-path system already exists end to end
(`PurchaseInvoiceSource` enum, `source` column, `chart_of_account_id` on items,
account-type validation, GL posting via `journalLines()`, badges on list/detail
pages). This spec is a refactor/merge of that existing, working code — not a new
build. Two premises in the originating request are corrected based on what's
actually in the codebase:

1. The non-stock `Account` dropdown is **already** filtered to `Expense` **and**
   `Liability` account types (not Expense-only) — Liability is needed for
   deduction lines like "Hutang PPh 23". This filter is **not** narrowed to
   Expense-only; it stays as-is.
2. `journalLines()` **already does real GL auto-posting** for both existing flows
   (Direct groups lines by `chart_of_account_id`; Goods Receipt posts a single
   `Dr 5100 Purchase Expense` aggregate line) — this is not a "backlog, not yet
   built" item as the originating request assumed. The stock line's account stays
   `5100 Purchase Expense` as it is today; it is **not** changed to hardcode
   `1300 Persediaan`. Extending this posting logic to handle the new `Mixed` case
   is in scope for this task (see Backend section) because merging the flows
   would otherwise silently break GL posting for any mixed document — it is not
   the separately-briefed "auto-posting" feature.

## UX Flow

1. **Step 1 — Supplier** (unchanged).
2. **Step 2 — Goods Receipt selection (optional)**
   - Same eligible-GR list, multi-select checkboxes, same-supplier lock behavior
     as today (`PurchaseInvoiceEditorPage.tsx` lines 131-195) — unchanged.
   - Add a **"Skip / Lanjutkan tanpa Goods Receipt"** button alongside the
     existing "Continue" button. Either button sets `selectionConfirmed = true`;
     Skip simply leaves `selectedGoodsReceiptIds` empty.
   - Remove the `mode: 'from_gr' | 'direct' | null` picker and its two buttons
     entirely — this step supersedes it.
3. **Step 3 — Line Items (unified)**
   - One table rendering both row kinds together:
     - **Stock rows** (from selected GR items): qty/item code read-only, rate
       editable — current `GoodsReceiptSourcedLineItemTable` row behavior,
       unchanged.
     - **Non-stock rows**: `Account` (CoA dropdown, Expense+Liability filter,
       unchanged), `Description`, `Qty`, `Rate`, `Tax` — current
       `DirectPurchaseInvoiceLineItemTable` row behavior, unchanged.
   - A persistent **"+ Add Non-Stock Line"** button at the bottom of the table,
     always available regardless of whether any GR rows are present.
   - Small badge per row ("From GR" vs "Manual") to distinguish locked vs
     editable rows.

### Validation

- Total line count (stock rows + non-stock rows) must be ≥ 1 to save/submit.
  Clear error if 0.
- Supplier required in all cases (unchanged).

## Data Model

### `line_type` (stock / non_stock) — derived, no migration

No new column. Computed wherever needed (resource/accessor):
`goods_receipt_item_id` present → `'stock'`; otherwise → `'non_stock'`. This
invariant already holds for every existing row (enforced today by
`resolveDirectLineAccount()` on the Direct path), so deriving it is safe and
avoids a redundant column that could drift out of sync.

### `source` (goods_receipt / direct / mixed) — enum extended, still a stored column

`PurchaseInvoiceSource` gets a new case `MIXED = 'mixed'`. `source` remains a
real column (unchanged schema) but its value is now **recomputed and persisted**
every time an invoice's items are created or updated:

- All lines are stock → `GOODS_RECEIPT`
- All lines are non-stock → `DIRECT`
- Both present → `MIXED`

Every existing `source`-branching call site gets a third arm:

- **`StorePurchaseInvoiceRequest` / `UpdatePurchaseInvoiceRequest`**: replace the
  `required_if:source,goods_receipt|direct` split with: supplier always
  required; `goods_receipt_ids[]`/`item_rates[]` validated when present;
  `items[]` (non-stock rows: `chart_of_account_id`, `description`, `uom`, `qty`,
  `rate`, `tax_id`) validated when present; at least one of the two groups must
  be non-empty.
- **`PurchaseInvoiceService`**: `createFromGoodsReceipts()` and `createDirect()`
  (and their `update*` counterparts) merge into one `create()`/`update()` path
  that independently processes whichever groups are present — GR row-locking +
  same-supplier + submitted + not-already-invoiced checks run only if
  `goods_receipt_ids` is non-empty; `resolveDirectLineAccount()` validation runs
  only on non-stock rows. `source` is set from the resulting line mix before
  save.
- **`PurchaseInvoice::journalLines()`**: emits the existing aggregate
  `Dr 5100 Purchase Expense` line when stock lines exist, **and** the existing
  per-`chart_of_account_id` grouped Expense/Liability lines when non-stock lines
  exist — both in the same journal entry when `source === MIXED`. Neither
  existing posting algorithm changes; this only lets both run for the same
  document.
- **Return eligibility** (`PurchaseInvoiceDetailPage.tsx`): condition changes
  from `!isDirect && returnable_amount > 0` to "has ≥1 stock line (any item with
  `goods_receipt_item_id`) `&& returnable_amount > 0`". Mixed invoices become
  eligible for Purchase Return on their stock lines; non-stock lines are never
  part of a return regardless (Purchase Return is already GR-item-based).
- **Badges** (list page `PurchaseInvoiceListPage.tsx`, detail page
  `PurchaseInvoiceDetailPage.tsx`): add a third label, `Mixed`, alongside the
  existing `Direct` / `Goods Receipt` badges.

### Backward compatibility

No data migration needed. Existing rows default to `source = 'goods_receipt'`
(already set by migration `2026_09_24_000001_add_source_and_relax_gr_po_fk_on_purchase_invoices.php`),
and none of the 23 existing invoices have a mixed line set — their derived
`line_type` values are already consistent with their stored `source`. The
derive-on-read approach means these rows need no data fix of any kind.

## Frontend Component Changes

- `PurchaseInvoiceEditorPage.tsx`: remove `mode` state and its picker UI; GR
  step always shown with Skip button; renders one unified form/table instead of
  branching into `PurchaseInvoiceForm` vs `DirectPurchaseInvoiceForm`.
- `PurchaseInvoiceForm.tsx` and `DirectPurchaseInvoiceForm.tsx` merge into a
  single form component that composes both row behaviors.
- `GoodsReceiptSourcedLineItemTable.tsx` and
  `DirectPurchaseInvoiceLineItemTable.tsx` merge into a single line-items table
  component rendering both row kinds with the "From GR" / "Manual" badge, plus
  the persistent "+ Add Non-Stock Line" button.
- `purchaseInvoiceFormSchema.ts`: merge the two zod schemas into one schema
  validating whichever groups are present, with the ≥1-total-line rule.

## Explicitly Out of Scope

- Any change to how GL posting *amounts/accounts* are derived for stock lines
  (stays `5100 Purchase Expense`, not `1300 Persediaan`) — a separate,
  previously-discussed task covers Item Group → inventory account mapping.
- Chart of Accounts duplicate-code cleanup (`510.xx.xx` legacy vs `5xxx` new
  codes) — tracked separately, does not block this task.
- Item Group / Maintenance changes for per-category inventory account mapping.

## Acceptance Criteria

- [ ] "New Invoice" no longer shows the From-Goods-Receipt vs Direct picker —
      flow goes straight to Supplier, then optional GR selection with a Skip
      button.
- [ ] User can create an invoice with: (a) only GR-sourced stock lines, (b) only
      manual non-stock lines, (c) a mix of both, in one document.
- [ ] GR-sourced lines stay locked (qty/item code read-only, rate editable) — no
      regression.
- [ ] Non-stock line `Account` dropdown still filters to Expense+Liability types
      (unchanged from today).
- [ ] Stock lines never expose an editable `Account` field.
- [ ] Saving/submitting with 0 total lines is blocked with a clear error.
- [ ] `source` badge shows `Goods Receipt` / `Direct` / `Mixed` correctly on
      listing and detail pages.
- [ ] `journalLines()` produces a correct combined entry for Mixed invoices
      (stock aggregate line + non-stock grouped lines), without changing either
      existing posting algorithm.
- [ ] Mixed invoices with stock lines are eligible for Purchase Return on those
      stock lines.
- [ ] All 23 existing invoices (Direct and Goods Receipt) still display,
      edit (if Draft), and submit correctly after the change — regression check.
      No data migration required or run.
