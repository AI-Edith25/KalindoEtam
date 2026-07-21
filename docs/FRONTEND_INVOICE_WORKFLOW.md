# Frontend — Invoice Workflow

Sprint 10. Inserts Invoice as its own Document Engine citizen between Delivery and Accounts Receivable — closing the gap where `DeliveryService::submit()` used to create Accounts Receivable directly. Same List + full-page Editor + Detail shell as Sales Order/Delivery, plus a fourth page (Print Preview) unique to Invoice.

---

## 1. Folder Structure

Added to the existing `features/sales/` module (Invoice is conceptually part of Sales, same reasoning Delivery used to join this folder rather than get its own):

```
features/sales/
  navigation.ts                       +Invoices entry in salesSectionNav
  types.ts                            +InvoiceItem, Invoice, InvoiceFormValues, InvoiceFilterValues, InvoiceDisplayStatus; Delivery +is_invoiced
  lib/
    invoiceFilters.ts                 emptyInvoiceFilters, hasActiveInvoiceFilters
    invoiceFormSchema.ts              zod schema — header fields only, no line items (see §2)
  api/
    invoiceApi.ts                     list (filtered), get, create, update, delete, submit, cancel
  pages/
    InvoiceListPage.tsx
    InvoiceEditorPage.tsx             two-phase: pick a Delivery, then the header form (read-only item preview)
    InvoiceDetailPage.tsx
    InvoicePrintPage.tsx              new page type — @media print layout, no PDF library
```

Backend (see architecture analysis presented before implementation for the full reasoning):

```
database/migrations/2026_07_25_000001_create_invoices_table.php          new
database/migrations/2026_07_25_000002_create_invoice_items_table.php     new
database/migrations/2026_07_25_000003_add_invoice_id_to_accounts_receivables_table.php   new
app/Models/Invoice.php, InvoiceItem.php                                  new
app/Models/Delivery.php                                                  +invoice() HasOne
app/Models/AccountsReceivable.php                                        +invoice_id, invoice(), receiptEntryItems()
app/Repositories/InvoiceRepository.php, InvoiceItemRepository.php        new
app/Services/InvoiceService.php                                          new
app/Services/AccountsReceivableService.php                               createFromDelivery() → createFromInvoice()
app/Services/DeliveryService.php                                         AR side-effect removed from submit() — BREAKING CHANGE, see §2
app/Http/Controllers/Api/V1/InvoiceController.php                        new
app/Http/Requests/{Index,Store,Update}InvoiceRequest.php                 new
app/Http/Resources/InvoiceResource.php, InvoiceItemResource.php          new
app/Http/Resources/DeliveryResource.php                                  +is_invoiced (derived, mirrors is_fully_delivered)
app/Http/Resources/AccountsReceivableResource.php                        +invoice_id
routes/api.php                                                           +invoices resource + submit/cancel
database/seeders/RolePermissionSeeder.php                                +invoice module
tests/Feature/InvoiceWorkflowTest.php                                    new — first test file in the project
```

Routes: `/sales/invoices` (list), `/sales/invoices/new` (create — starts with Delivery selection), `/sales/invoices/:id/edit` (edit, draft-only, header fields only), `/sales/invoices/:id` (detail), `/sales/invoices/:id/print` (print preview).

---

## 2. Workflow

```
Delivery (submitted / "delivered", not yet invoiced)
        ↓
Select Delivery
        ↓
Items are copied server-side from the Delivery's own items — never entered by the user
        ↓
Enter discount / tax / invoice date / due date (header only)
        ↓
Save Draft (editable — assertDraft() guard, same pattern as every other document)
        ↓
Submit (status → submitted, immutable from here; Accounts Receivable created from Invoice.grand_total)
        ↓
Cancel → Create New is the only correction path once submitted (blocked if any payment has been applied)
```

**The breaking change this sprint made, and why:** before this sprint, `DeliveryService::submit()` created the Accounts Receivable record directly (`AccountsReceivableService::createFromDelivery()`). The business requirement is that Invoice — not Delivery — is what generates Accounts Receivable. `DeliveryService::submit()` no longer creates AR at all; `InvoiceService::submit()` does, via the renamed `AccountsReceivableService::createFromInvoice()`, sourcing `amount` from the Invoice's `grand_total` (which includes tax/discount) instead of re-summing Delivery lines. This was flagged explicitly in the pre-implementation architecture analysis and confirmed against the ticket's own workflow diagram before any code was touched.

**No manual line entry anywhere in this flow** — the Editor's Item List is read-only, sourced from the selected Delivery (create) or the already-created Invoice's own items (edit). This isn't a UI restriction layered on top of a flexible backend; `InvoiceService::create()` structurally copies `DeliveryItem` rows into `InvoiceItem` rows and the Store/Update requests have no `items` field at all.

---

## 3. API Integration

| Action | Endpoint |
|---|---|
| List (filtered) | `GET /invoices?search=&status=&customer_id=&delivery_id=&date_from=&date_to=&per_page=&page=` |
| Get one | `GET /invoices/{id}` |
| Create | `POST /invoices` — `{delivery_id, invoice_date, due_date, discount_amount?, tax_amount?, remarks?}` |
| Update (draft only) | `PUT /invoices/{id}` — same fields except `delivery_id` |
| Delete (draft only) | `DELETE /invoices/{id}` |
| Submit | `POST /invoices/{id}/submit` |
| Cancel | `POST /invoices/{id}/cancel` — blocked (422) if the linked Accounts Receivable has `paid_amount > 0` |

Dropdown/lookup data: `GET /deliveries?status=submitted&per_page=100` (candidate Deliveries, filtered client-side to `!is_invoiced` — same pattern Delivery's own Editor uses for `!is_fully_delivered` against Sales Orders).

**Numbering:** plain sequential `INV-00001`, reusing `DocumentNumberGeneratorService`/`NamingSeries` exactly as every other document type does — the `invoice` naming series row was already seeded (`DocumentEngineSeeder`) in anticipation of this module. No changes to the shared numbering engine; a monthly-reset format (`INV-202607-00001`) was considered and explicitly declined in favor of consistency with `SO-`/`DN-`/every other prefix.

---

## 4. Status Model

Invoice's own `status` column is the same 3-value `DocumentStatus` (`draft/submitted/cancelled`) every `Documentable` uses — no new column, no new enum. The 5-value status the UI shows (Draft / Submitted / Partially Paid / Paid / Cancelled) is **derived**, computed server-side in `InvoiceResource::resolveDisplayStatus()` by combining `status` with the linked Accounts Receivable's own `AccountsReceivableStatus` (`unpaid/partially_paid/paid`). Paid-amount tracking lives in exactly one place — `AccountsReceivableService::settle()`, already exercised by Receipt Entry — Invoice never duplicates it. `StatusBadge` needed no code change: its existing fallback already renders any status string it doesn't recognize, and `partially_paid`/`paid` were already mapped for Accounts Receivable/Payable.

---

## 5. Reusable Components

**Reused as-is:** `PageHeader`, `ActionBar`, `SearchBox`, `DataTable`, `RowActionsMenu`, `Pagination`, `StatusBadge`, `DeleteDialog`, `DetailField`/`DetailSection`, `Card`/`Textarea`/`Select`/`Form`, `SectionNav` — the entire document-editor shell, unchanged.

**Reused directly, not copied:** `SalesOrderFiltersBar` — Invoice's filter shape (`status`/`dateFrom`/`dateTo`) is structurally identical to Sales Order's, so `InvoiceListPage` imports it directly rather than creating a near-duplicate `InvoiceFiltersBar`.

**New, entity-specific:** a small `PreviewLine` shape in `InvoiceEditorPage` that both `DeliveryItem` (before creation) and `InvoiceItem` (after creation) satisfy structurally, so one read-only item table serves both the "picking a Delivery" and "editing a draft Invoice" states without a second component.

**New, cross-cutting:** `AppLayout` now hides its Sidebar/Header/Breadcrumbs under Tailwind's `print:hidden` variant (three small className additions) — a one-line, reusable fix that makes every page in the app print cleanly, not just Invoice's. `InvoicePrintPage` itself is plain HTML/CSS with a `window.print()` button; no PDF library, no server-side rendering.

---

## 6. Design Decisions & Trade-offs

- **Items are copied, not re-entered.** The alternative — letting a user pick line items on the Invoice form — would let an Invoice drift from what was actually delivered, and duplicates validation the Delivery already did. `InvoiceService::create()` copies `DeliveryItem` rows verbatim (including `item_code`/`item_name`/`uom` snapshots) into `InvoiceItem` rows; the Editor's Item List is deliberately read-only with an explanatory note ("cancel and re-invoice if the Delivery was wrong") rather than a disabled-looking editable table.
- **"Invoiced" is derived, not a stored flag.** `Delivery.status` is the shared `DocumentStatus` enum that `Documentable`'s `submit()`/`cancel()` guard against directly — adding a 4th "invoiced" value would have broken those guards for every document type sharing the enum. Instead, `invoices.delivery_id` carries a database-level `unique()` constraint (the actual enforcement of "never twice"), and `Delivery::invoice()` / the derived `is_invoiced` resource field is a read, not a write.
- **Tax and discount are plain manual amounts, not a Tax-engine calculation.** The ticket explicitly excluded a Tax Engine from this sprint's scope, and no existing Sales/Delivery line item has a tax field to build on. `discount_amount`/`tax_amount` are entered as flat numbers at the invoice-header level and folded into `grand_total` — the same "don't build what wasn't asked for" reasoning kept `journalLines()` a pure computed method instead of a persisted Journal Entry table.
- **Cancel is guarded by payment state, not blocked outright.** Delivery/PaymentEntry/ReceiptEntry all override `cancel()` to throw unconditionally, because none of them have a defined correction workflow. Invoice is different — "Cancel → Create New" is the explicit correction path in the business rules — so it keeps `Documentable`'s default `cancel()`, with `InvoiceService::cancel()` adding exactly one guard: refuse if the linked Accounts Receivable already has `paid_amount > 0`, since there is no partial-reversal workflow for money already received.

---

## 7. Recommendation for the Next Module

**Journal Entry.** `Invoice::journalLines()` already produces the debit/credit breakdown (Dr Accounts Receivable, Cr Sales Revenue, Cr Tax Payable, Dr Discount Given) from fields already on the Invoice — a future Journal module has a ready-made, already-correct source to post from rather than re-deriving accounting data from raw Invoice rows. The `naming_series` table already has a seeded `journal → JE-` row anticipating this, the same way `invoice → INV-` was seeded ahead of this sprint.

One thing worth carrying forward specifically from this sprint: when a new document type sits between two existing ones in a workflow, check whether the *existing* side-effect wiring (here, `DeliveryService::submit()` creating AR) needs to move rather than just adding new code alongside it. The architecture analysis phase — done before any code was written, per the ticket's own instruction — is what surfaced this as the one unavoidable breaking change instead of it being discovered mid-implementation.
