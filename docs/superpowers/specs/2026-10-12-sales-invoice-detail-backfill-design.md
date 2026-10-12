# Sales Invoice Detail Backfill — Design

## Problem

`CustomerOutstandingArchiveImportService` (shipped 2026-10-08+) creates a real Invoice per
unpaid/overdue bill from the legacy "Customer Unpaid Bills With Overdue Advice" export, but that
file has no line-item detail — every such Invoice gets exactly one freeform InvoiceItem whose
`item_name` is just the ref_no (fixed 2026-10-12, previously `"Outstanding Balance — {ref_no}"`).
The printed paper Sales Invoice for one of these therefore shows a single generic line instead of
the invoice's real items/qty/price — confirmed live on production (e.g. `TR/KE/07644/10/2026`).

The old system's full-detail export, "Sales Invoice Listing - Detail" (one header row + item rows
per document — the exact file format `SalesInvoiceHistoryParser` already parsed before it was
removed 2026-10-08), has that real detail. This feature imports it, but **only to fill in detail
on Invoices that already exist** in this system from the Outstanding Bills import — it never
creates a new Invoice, and it never touches the print template/paper layout, only the underlying
data.

## Non-goals

- Does not revive the old "Sales Invoice history" import (which created new Invoices). The old
  parser's *file-reading* code is reused verbatim (pure parsing, no DB writes); the write-side
  logic here is entirely new and only ever updates an existing row.
- Does not change `InvoiceType::GOODS`/`TRANSPORTATION` layout components, paper print templates,
  or any frontend print page. Backend data only.
- Does not touch `grand_total`, `customer_id`, `invoice_date`, `due_date`, `status`, `warehouse_id`,
  `location_warehouse_id`, stock, or GL. Those are already correct/validated from the Outstanding
  Bills import and are out of scope.
- Does not create/edit Customer, Item, or MiscellaneousItem master data (map-to-existing-or-skip
  only, same posture as every other importer in this app).

## File format

Reuses `SalesInvoiceHistoryParser` (restored from git history, commit `19570d3^`, unchanged) —
confirmed against a real export (`xlsSalesInvoiceListing_Detail (1).xlsx`, 3,778 rows):

- Row 1: title containing "SALES INVOICE LISTING". Header row located by finding `DOCUMENT #`
  among its cells (not a hardcoded row number).
- Header row + items: one invoice header row (`DATE, DOCUMENT #, CUSTOMER#, NAME, …, DISC, TAX,
  T.CODE, AMOUNT, REFERENCE 1 #, REFERENCE 2 #`) followed by 1+ item rows (`ITEM #, …,
  DESCRIPTION, …, UOM, QUANTITY, UNIT PRICE, DISC, TAX, T.CODE, LINE AMOUNT`) until the next
  header row (col 0 parses as a date) or the `TAX SUMMARY`/`Printed By` trailer.
- `LINE AMOUNT = qty × rate + TAX` (confirmed against real rows: `30 × 31531.54 + 104054.08 =
  1050000.28`).
- DOCUMENT # prefix (tokenized, not `explode('/', …)`, so `SI-KE-`/`SI/KE/` both work) decides
  Goods vs Transportation, same as `CustomerOutstandingArchiveImportService::resolveInvoiceType()`.
- The real export also carries `ITEM BATCH` / `SALES PERSON CODE` / `SALES PERSON NAME` /
  `LOCATION` columns after `REFERENCE 2 #` that the old parser's docblock didn't document (its own
  LOCATION column position assumption is stale) — irrelevant here since this feature never reads
  or writes location/warehouse at all.

## Matching

For each parsed invoice group, find an existing Invoice where:
- `source_document_number_normalized` (via `DocumentKeyNormalizer::normalize()`, not raw string
  equality — handles `SI/KE/…` vs `SI-KE-…` format differences) equals the file's normalized
  `DOCUMENT #`, **and**
- `import_source_type = 'outstanding_bills_archive'` (only these have the generic placeholder
  line; a manually-entered or normally-created Invoice is never touched, even if its number
  happens to match).

No match → `skip_not_found`.

## Validation (all three must pass, else skip + report — never partially apply)

1. **Customer**: file's `CUSTOMER#` must equal the matched Invoice's `Customer.customer_code`.
   Mismatch → `skip_customer_mismatch` (guards against a coincidental number collision pulling in
   the wrong detail).
2. **Type**: file group's type (Goods/Transportation, from the DOCUMENT # prefix) must equal the
   matched Invoice's `invoice_type`. Mismatch → `skip_type_mismatch`.
3. **Amount**: recompute from the file's own item rows —
   `subtotal = Σ(qty × rate)`, `tax_amount = Σ(item TAX)`, `discount_amount = header DISC`,
   `computed_grand_total = subtotal − discount_amount + tax_amount` — and compare against the
   Invoice's existing `grand_total`. Tolerance **1.00** (not the usual 0.01 elsewhere in this app):
   this is a sum over many lines from decades-old legacy data, so small per-line rounding drift
   compounds; 0.01 produced false mismatches in the old (now-removed) importer's own history for
   exactly this reason. Beyond tolerance → `skip_amount_mismatch`.

## Item resolution (Goods invoices only; Transportation items are always freeform, no lookup —
same as `InvoiceService::createTransportation()` and the old parser's documented behavior)

For each item row's `ITEM #`: try `Item.item_code` first, then `MiscellaneousItem.misc_code` if
unresolved (a Goods document can carry a misc charge line, e.g. a "TRANSPORT" line embedded in an
SI document — same as the old, now-removed `SalesInvoiceImportService::classifyItemsOrMisc()`).

If **any** item row in the group resolves to neither → the whole group is `skip_item_unresolved`
(no partial update of one invoice's items — matches the already-decided "skip the whole invoice on
any problem" posture, and is simpler/lower-risk than the old importer's manual-resolution UI,
which this feature deliberately does not build: skipped invoices can always be retried once master
data is fixed).

## Write (commit, `will_update` groups only, one DB transaction per invoice)

1. Delete the Invoice's existing InvoiceItem row(s) (the generic placeholder).
2. Insert one InvoiceItem per file item row:
   - Matched real Item: `item_id`, `item_code`, `item_name` = Item's own name, `uom` = Item's UOM
     name, `qty_category` = Item's own category (rounded to its `decimalPlaces()`).
   - Matched MiscellaneousItem: `item_id` null, `item_name` = Misc's description, `qty_category`
     = Weight (same convention as Transportation's freeform lines).
   - `rate`, `qty`, `amount` (= qty×rate, rounded 2dp), `tax_amount` (the item's own TAX, rounded
     2dp) — straight from the file, never recomputed against a current Item price/tax rate (this
     file's numbers are historical and frozen, same principle as every other historical importer
     in this app).
3. Update the Invoice header: `subtotal`, `discount_amount` (type stays `AMOUNT`), `tax_amount`,
   `reference_1`, `reference_2`. `grand_total` is left untouched (already validated above).

Idempotent/re-runnable: re-uploading the same (or an overlapping) file reprocesses already-detailed
invoices identically (delete+reinsert the same rows) — no extra "already done" flag needed.

## Interface

Full UI import feature, mirroring `CustomerOutstandingArchiveController` /
`CustomerOutstandingArchiveImportDialog` exactly (synchronous preflight+commit, no queue — same
"files are small enough" posture as that controller):

- **Backend**: `SalesInvoiceDetailBackfillService::preflight()`/`commit()`, a thin orchestration
  controller + request validator + route, new `ImportBatch` module
  (`sales-invoice-detail-backfill`), rejected-rows CSV via the existing
  `ImportErrorReportWriter::attachRejectedRows()` (one row per skip reason, reusing the pattern
  from `CustomerOutstandingArchiveController::rejectedRowsReport()`).
- **Permission**: new permission added via `RolePermissionSeeder` (never a migration — this app's
  deploy always re-runs `db:seed RolePermissionSeeder`, which replaces all role grants; a
  migration-only grant would be silently wiped on the next deploy).
- **Frontend**: new import dialog component, entry point button on the Sales Invoice List page
  (next to "New Invoice"), preview shows will_update count + a breakdown per skip reason + a
  "download rejected rows" link, confirm → commit → toast with final counts.

## Testing

Feature test(s) building fixture Invoices directly via Eloquent (`import_source_type =
'outstanding_bills_archive'`, one generic InvoiceItem each — mirrors
`CustomerOutstandingArchiveSiCreationTest`'s own setup style) plus a small in-memory CSV/XLSX
fixture shaped like the real file, covering: a clean match (items replaced, header updated, grand
total untouched), `skip_not_found` (number not in the system), `skip_customer_mismatch`,
`skip_type_mismatch`, `skip_item_unresolved` (bad ITEM # on Goods), `skip_amount_mismatch` (beyond
the 1.00 tolerance), a Misc-matched line (no Item master match), and a Transportation group
(freeform items, no Item lookup attempted). A Goods/non-outstanding-bills-archive Invoice sharing
the same document number must never be touched.
