# Tax Engine — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests, no frontend pages until approved.

Frozen inputs this design must not modify: **Accounting Engine, Invoice, Credit Note, Debit Note, Accounts Receivable, Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet, Cash Flow, Period Closing**. This design proposes a **minimal extension** to the already-existing `taxes` table (two new columns) and one new nullable column on `invoices` — no new table, no change to any frozen table's existing columns. Nothing in this document is migrated or implemented — it is a proposal, in the same code-block-sketch style every prior `*_DESIGN.md` already uses.

## 0. What already exists (grounding) — the central discovery of this sprint

Confirmed by inspecting the running app (Chrome — Sales, Purchase, Journal Entries, Accounting Reports, Master Data) and reading `Tax`/`TaxService`/`TaxController`/`TaxRepository`/`TaxResource`/`InvoiceService`/`Invoice` before writing anything below:

- **A `Tax` model, `TaxRepository`, `TaxService`, `TaxResource`, `TaxController`, and full `taxes` migration already exist**, and `Route::apiResource('taxes', TaxController::class)` is already registered — confirmed by reading every file. This is not a greenfield design; it's an **activation** of dormant infrastructure, the same shape Sprint 20B found for Role/Permission (seeded, never enforced) and Sprint 18A found for Settings (a sidebar item, no route).
- **The existing `Tax` model is `{id, name, rate, is_active}` only** — no `code`, no `type`. `TaxService` today is a **bare CRUD wrapper** (`list/create/update/delete`, confirmed by reading the full 34-line file) — despite its name, it contains **zero calculation logic**. This is the literal gap this sprint exists to close.
- **`Invoice.tax_amount` is a flat, manually-typed currency number**, completely disconnected from the `Tax` model — confirmed in `InvoiceService::create()`/`update()` (`$taxAmount = $data['tax_amount'] ?? 0`, a raw request value, no `tax_id`, no rate lookup) and live in the browser (Invoice Detail shows `Tax Rp 20.000` with no rate, code, or name attached — just a number a user typed into a box). The identical `tax_amount` field exists on Credit Note and Debit Note with the same disconnected shape.
- **`Invoice::journalLines()` posts `tax_amount` to `2100 Tax Payable` via a hardcoded account-code string** (`'account' => '2100'`) inside the model itself — confirmed by reading the method. This is a **routing** concern, not a **calculation** concern, and it already works correctly; this design does not touch it (§1/§6).
- **Purchase has zero tax handling today** — confirmed both in code (no `tax_amount` column anywhere in `purchase_orders`, no tax logic in `PurchaseOrderService`) and live (the Purchase Orders list's columns are Document Number / Supplier / Date / Status / Receiving Progress / Total Amount — no Tax column at all). Purchase's own document lifecycle currently stops at Goods Receipt / Accounts Payable — **no Purchase Invoice/Bill document type exists yet** in this codebase (Purchase's own live sub-nav is just Orders / Goods Receipts).
- **Master Data already has seven live tabs** (Items, Suppliers, Customers, Warehouses, Item Groups, UOMs, Chart of Accounts) — confirmed live. **No Taxes tab exists**, despite `TaxController` being fully built server-side — the same "backend ships ahead of any UI" pattern this whole design series keeps encountering.
- **`Company` has no tax-registration field** (`name/code/address/phone/email/currency/timezone/fiscal_year_start` only, reconfirmed from this session's own prior grounding) and **no frontend page exists for it at all** (`/companies` is unmapped in `router.tsx`) — "Company Information," per this ticket's own inspection list, could not actually be inspected live. Noted honestly rather than assumed.
- **This is an Indonesian-context ERP** (currency `IDR`, timezone `Asia/Jakarta`, confirmed from `Company` seed data) — the ticket's own "VAT / PPN" naming matches Indonesia's real VAT (Pajak Pertambahan Nilai). This context directly informs §9: multiple concurrent VAT rates are a near-term real need here (PPN's own rate has changed by law before), not a hypothetical.

---

## 1. Tax Engine Architecture

**Core decision: separate tax CALCULATION (centralized, reusable) from tax POSTING/GL routing (stays exactly where it already lives, inside each document's own `journalLines()`, unchanged).** This is the same separation this entire design series already relies on — `GeneralLedgerService` centralizes balance computation while every report keeps its own presentation layer (`CASH_FLOW_DESIGN.md §1`); here, `TaxService` centralizes tax *math* while each document module keeps its own journal routing. This is what lets Sales, Purchase, and future modules share one engine without any of them being redesigned, and it's why this design touches zero lines of `Invoice::journalLines()`.

```
                         ┌─────────────┐
                         │  TaxService │   ← the reusable calculation core
                         │ calculate() │     (existing list/create/update/delete
                         └──────┬──────┘      unchanged — §0)
                                │
                 baseAmount, Tax, mode → {tax_amount, base_amount, total}
                                │
              ┌─────────────────┼──────────────────┐
              ▼                                     ▼
     InvoiceService (Sales, exists)        PurchaseOrderService (Purchase, exists,
       calls calculate() instead of          currently no tax — future consumer,
       trusting a typed number                same call, same service)
              │                                     │
              ▼                                     ▼
       Invoice::journalLines()              (future) journalLines()
       UNCHANGED — still just reads          same pattern, its own account
       $this->tax_amount, posts to           routing, its own concern —
       2100 (§0) — has no idea the           TaxService never posts anything
       number came from TaxService           itself, on either side
```

`TaxService` is a **stateless calculator**. It never becomes a `Documentable`, never posts a journal entry, never knows Invoice or PurchaseOrder exist. Every document module stays fully in charge of *when* to call it and *where* the resulting number gets journaled — the minimum possible surface for "reusable," and the reason this design requires zero changes to the frozen Accounting Engine.

---

## 2. Tax Entity Design

The ticket's five required fields, mapped against what already exists (§0):

| Field | Status | Why it exists |
|---|---|---|
| **Tax Code** | New | A short, stable identifier for lookups/imports/exports and for printing on a tax invoice (Faktur Pajak, in this app's Indonesian context) — deliberately separate from `name` the same way `chart_of_accounts.code` is already separate from `chart_of_accounts.name` in this codebase. Never used in calculation itself (`rate` is); purely identity/display, the same role `document_number` plays for transactional documents. |
| **Tax Name** | Existing (`name`) | The human label shown in every dropdown/report ("PPN 11%"). Kept independently editable from `code`, the same code/name split every other master entity here already has. |
| **Tax Type** | New | Distinguishes *calculation behavior* from *rate* (§3). Without it, `rate = 0` can't distinguish "Zero-Rated" from "Exempt" — legally distinct VAT categories (a Zero-Rated sale still counts toward taxable turnover and needs a tax invoice; an Exempt sale doesn't) that happen to calculate to the identical `Rp 0`. |
| **Tax Rate** | Existing (`rate`, `decimal:5,2`) | The multiplier `TaxService::calculate()` actually uses. Lives on the `Tax` record, not hardcoded in a document, so a real rate change (PPN has changed by law before) updates every future calculation the instant the record is edited — zero Sales/Purchase code touched. |
| **Status (Active/Inactive)** | Existing (`is_active`) | Governs *selectability* on new documents only — never retroactively touches already-posted transactions, the same "never rewrite history" principle `PERIOD_CLOSING_DESIGN.md` already established. Deactivating a superseded rate leaves every old document that already used it fully intact. |

Proposed extension (2 new columns on the *existing* `taxes` table — not a new table):

```php
Schema::table('taxes', function (Blueprint $table) {
    $table->string('code')->unique()->after('id');   // e.g. "PPN11", "PPN0", "EXEMPT"
    $table->string('type')->after('name');             // TaxType enum value
});
```

```php
enum TaxType: string
{
    case VAT = 'vat';
    case ZERO_RATED = 'zero_rated';
    case EXEMPT = 'exempt';
}
```

Withholding tax is deliberately **not** a case here — see §Tax Types below.

---

## 3. Tax Types

Ticket's minimum set — `VAT`, `Zero Rated`, `Tax Exempt` — all three map directly onto `TaxType` (§2). Behaviorally:

- **VAT**: `rate` is applied via `TaxCalculationMode` (§4). The only type that ever produces a non-zero `tax_amount`.
- **Zero Rated**: a real, selectable Tax row (so a Zero-Rated export sale still records *which* zero-rated treatment applied, for future tax reporting) whose calculation always yields `Rp 0`, regardless of any `rate` value stored — `TaxService::calculate()` short-circuits on type, never multiplies a Zero-Rated row's rate.
- **Exempt**: same zero-yield behavior as Zero-Rated, kept as a *distinct* type (not the same row) because the two mean different things for a future tax report (§9), even though today's calculation output is identical.

**Withholding tax — deliberately not included, per the ticket's own "do not implement... unless there is a strong architectural reason":** withholding tax isn't a rate-on-amount calculation at all — the *payer* withholds and remits on the *payee's* behalf, needs its own liability/remittance flow and its own reporting cycle, not a `TaxCalculationMode`. Forcing it into this `TaxType` enum as a same-shape case it structurally isn't would be the exact kind of premature complexity the ticket warns against. It's named explicitly in §9 as a future, separate design — the same "flagged, not solved" discipline `PERIOD_CLOSING_DESIGN.md §2` already used for Year-End Closing.

---

## 4. Tax Calculation Flow

**Tax Exclusive** (rate applied on top of the base amount — this is what `Invoice` already assumes today, since `grand_total = subtotal − discount + tax_amount`, §0):

```
tax_amount = base_amount × rate / 100
total      = base_amount + tax_amount
```

**Tax Inclusive** (the base amount already contains tax; the rate is backed out):

```
net_amount = base_amount / (1 + rate / 100)
tax_amount = base_amount − net_amount
total      = base_amount                     (unchanged — tax was already inside it)
```

```php
enum TaxCalculationMode: string
{
    case EXCLUSIVE = 'exclusive';
    case INCLUSIVE = 'inclusive';
}

class TaxService
{
    // ... existing list()/create()/update()/delete() unchanged (§0) ...

    /** @return array{tax_amount: float, base_amount: float, total: float} */
    public function calculate(float $baseAmount, ?Tax $tax, TaxCalculationMode $mode): array
    {
        if (! $tax || $tax->type !== TaxType::VAT) {
            // Zero-Rated, Exempt, and "no tax selected" all resolve identically — callers
            // never need to branch on tax type themselves, only ever call calculate().
            return ['tax_amount' => 0.0, 'base_amount' => $baseAmount, 'total' => $baseAmount];
        }

        $rate = (float) $tax->rate;

        if ($mode === TaxCalculationMode::INCLUSIVE) {
            $net = $baseAmount / (1 + $rate / 100);
            $taxAmount = round($baseAmount - $net, 2);

            return ['tax_amount' => $taxAmount, 'base_amount' => round($net, 2), 'total' => $baseAmount];
        }

        $taxAmount = round($baseAmount * $rate / 100, 2);

        return ['tax_amount' => $taxAmount, 'base_amount' => $baseAmount, 'total' => round($baseAmount + $taxAmount, 2)];
    }
}
```

Same `round(..., 2)` discipline `BalanceSheetService`/`CashFlowService`/`TrialBalanceService` already use — no new rounding convention invented. `$baseAmount` is deliberately generic (not "Invoice subtotal" specifically) — `TaxService` has no opinion on whether the caller passes a whole document's subtotal or a single line's amount; that's a caller decision (§5).

---

## 5. Tax Assignment Strategy

Ticket asks to evaluate five attachment points:

- **Product (Item)** — tempting, but `Item` is a single catalog shared by *both* Sales and Purchase (confirmed: one Items tab under Master Data, §0). A per-item *default* tax is real future convenience (§9, Tax Groups), but can never be the sole enforcement point — the same item can be Zero-Rated on an export sale and standard-VAT on a domestic one; tax treatment is a transaction fact, not a fixed item property.
- **Customer / Supplier** — same reasoning: a useful *default* to pre-fill (e.g. a known tax-exempt organization), never authoritative, since the same customer can receive both taxable and zero-rated transactions.
- **Transaction Line** — the granular, "correct in general" option for mixed-tax baskets in one document. Rejected *for this design* because `Invoice` today has exactly **one** `tax_amount` at the document header, not per line (§0) — moving to per-line tax would touch `InvoiceItem`'s schema, `Invoice::journalLines()`'s aggregation, the Invoice editor's line grid, and `AccountsReceivableService`: a redesign of a frozen module, which this ticket forbids.
- **Document** — matches exactly what already exists (`Invoice.tax_amount`, a header field). Zero schema change to `invoices`/`invoice_items` needed to adopt it beyond the one new column below.

**Recommendation: Document-level assignment** — a `tax_id` selected once per document header, with the *calculation* moving from "a human types a number" to "`TaxService` computes it from the selected `Tax`'s rate against the document's subtotal." This is the minimal, lowest-risk change that satisfies the ticket's actual goal (stop hand-typed, duplicated tax logic; make it one real reusable service) without redesigning `Invoice`'s shape or touching the frozen Accounting Engine's line structure.

```php
Schema::table('invoices', function (Blueprint $table) {
    $table->foreignUuid('tax_id')->nullable()->after('discount_amount')->constrained('taxes')->nullOnDelete();
});
```

`tax_amount` (existing column) becomes a **cached, derived value** — the same "cache column, not source of truth" pattern this codebase already uses for `journal_entries.total_debit`/`total_credit` and `accounts_receivable.credited_amount`. `InvoiceService::create()`/`update()` change from trusting `$data['tax_amount']` directly to calling `TaxService::calculate()` and storing its result into that same column (§6) — every other consumer of `tax_amount` (`journalLines()`, `AccountsReceivableService`, every report) reads the identical column, unaware anything changed.

Per-line tax assignment is named explicitly as a **future** upgrade path (§9 — Tax Groups / mixed-tax documents), not built now.

---

## 6. Tax Service — the single source of truth

`TaxService` gains exactly **one** new public method, `calculate()` (§4), alongside its existing, unchanged `list()`/`create()`/`update()`/`delete()` (§0). Every module calls the identical method:

```php
// InvoiceService::create() — the only change from today's $data['tax_amount'] ?? 0 (§0)
$tax = isset($data['tax_id']) ? $this->taxRepository->findOrFail($data['tax_id']) : null;
$mode = TaxCalculationMode::from($data['tax_mode'] ?? TaxCalculationMode::EXCLUSIVE->value);
['tax_amount' => $taxAmount] = $this->taxService->calculate($subtotal, $tax, $mode);
```

```php
// PurchaseOrderService (future — once Purchase gains a tax field, the identical call, zero duplication)
['tax_amount' => $taxAmount] = $this->taxService->calculate($subtotal, $tax, $mode);
```

This is the same "one calculation, many callers" shape `GeneralLedgerService::listAccounts()` already has relative to Trial Balance/Profit & Loss/Balance Sheet/Cash Flow (`CASH_FLOW_DESIGN.md §1`) — a proven pattern in this codebase, now applied to tax instead of ledger balances. `InvoiceService`/`PurchaseOrderService` constructor-inject `TaxService` the same way they already inject `AccountsReceivableService`/`AccountingService` — no new dependency-injection pattern introduced.

**Avoiding duplication, concretely**: today, if Purchase ever needed tax, the only path would be re-typing the same rate-multiplication logic Invoice never actually formalized in the first place (§0 — there IS no formalized logic today, only a trusted number). With `TaxService::calculate()` existing, Purchase's future tax support is a call, not a reimplementation.

---

## 7. Reporting Impact

| Report/Document | Impact |
|---|---|
| **Sales Invoice** | `tax_amount` becomes *calculated* instead of *typed*; the column, its type, and every downstream reader stay identical. No visible change to Invoice Detail's own "Tax Rp X" line. |
| **Purchase Invoice** | Doesn't exist as a document type yet (§0) — out of scope to design its full lifecycle here. Once built, it is the natural second consumer of the identical `TaxService::calculate()` call. |
| **Journal Entries** | `Invoice::journalLines()` is **unchanged** — it already reads `$this->tax_amount` and posts to `2100 Tax Payable` (§0); it has no way to know whether that number came from a human or `TaxService`. Zero lines of `journalLines()` change. |
| **Profit & Loss** | Unaffected — `tax_amount` posts to a Liability account (`2100`), never touches any Revenue/Expense account P&L reads (`PROFIT_LOSS_DESIGN.md §4`'s own mapping already excludes it). True by construction. |
| **Balance Sheet** | Unaffected in structure — `2100 Tax Payable` is already mapped to `current_liability` (`BALANCE_SHEET_DESIGN.md §4`); only the *posted amount* changes (now calculated, not typed), the same "what data flows in" vs. "how the report reads it" separation `PERIOD_CLOSING_DESIGN.md §2` already established for a different kind of change. |
| **Cash Flow** | Unaffected — `2100 Tax Payable` is already mapped to `operating_adjustment` (`CASH_FLOW_DESIGN.md §5`); same reasoning. |

No report design changes anywhere. This is the direct payoff of §1's core decision: every report in this module already reads `journal_entries` through the same account-code classification mechanism, regardless of how the number that got posted was computed.

---

## 8. Navigation Recommendation

**Master Data**, not Administration — even more clear-cut than Period Closing's own placement (`PERIOD_CLOSING_DESIGN.md §6`):

- Tax is **reference/master data** by nature — a picklist entity (like Currency, UOM, Item Group), not a workflow or write-action (unlike Period Closing, which was a genuine action-workflow and still landed under a domain section rather than a nonexistent Administration one).
- Confirmed live: Master Data already has seven tabs; `Currency` sits in the exact same "backend CRUD exists, no frontend page yet" state as Tax (`Route::apiResource('currencies', ...)`, confirmed §0) — Taxes belongs as an eighth tab in that same list, the same growth pattern already established.
- "Administration"/Settings still isn't a built section (`PERIOD_CLOSING_DESIGN.md §0`, reconfirmed unchanged this sprint) — same reasoning applies again.

Route: `/master/taxes`, alongside `/master/items`, `/master/chart-of-accounts`, `/master/warehouses`.

---

## 9. UI Design

Reuses the exact components every Master Data and report page in this app already uses — nothing new:

- `PageHeader` + `ActionBar` (Refresh; **New Tax** as the primary action) — same as every list page.
- `DataTable`: **Code | Name | Type | Rate | Status | Actions**.
- `StatusBadge`: `is_active` renders via the **existing** `active`/`inactive` color mapping already in `STATUS_STYLES` (confirmed present from this session's own Sprint 20B work) — zero new colors needed.
- **Create / Edit**: a `Dialog`-based form, the same shape Period Closing's own "New Fiscal Year" dialog already established (`PERIOD_CLOSING_DESIGN.md §7`) — Code, Name, Type (`Select`: VAT / Zero-Rated / Exempt), Rate (`Input`, disabled and forced to `0` when Type ≠ VAT, preventing a confusing "Zero-Rated, 11%" combination from ever being saved).
- **Activate / Deactivate**: not a delete — a single row action toggling `is_active` through the **existing** `PUT /taxes/{tax}` endpoint (§0, already built), the same "soft toggle, never destroy" convention `ChartOfAccount.is_active`/`Item.is_active` already establish elsewhere in this app.
- No detail/drill-down page — the same "list plus inline dialogs is enough for a small reference table" call already made for Period Closing's own Fiscal Year flow.

---

## 10. Future Expansion Strategy

- **Multiple VAT rates** — already native. `Tax` is already a table of many rows (e.g. a current `PPN11` alongside a superseded `PPN10`); nothing in this design assumes exactly one active VAT rate at a time.
- **Tax Groups** (a document needing more than one tax stacked, e.g. VAT plus a local levy) — add a `TaxGroup` model `hasMany` `Tax`, referenced instead of a single `tax_id`. `TaxService::calculate()` already returns a structured breakdown array, not a bare number (§4) — extending it to sum multiple `Tax` rows' contributions is additive to the same method signature, not a redesign.
- **Withholding Tax** — deliberately deferred (§3), the identical "flagged, not solved" discipline Period Closing used for Year-End Closing. Needs its own liability/remittance design, not a `TaxType` case.
- **Multi-country taxation** — `Tax` gaining an optional, nullable `country_code` (defaulting to Indonesia-only today) plus a future `Company`-level tax-registration field (§0 — Company has none today) would scope *which* taxes are offered per company/country without touching `TaxService::calculate()`'s signature at all — the math itself (rate × base, inclusive/exclusive) is already country-agnostic; only the *selectable list* would need country-scoping, an additive filter.

---

## 11. Browser Verification Report

Performed against the running app (`php artisan serve` + `vite dev`) before writing this design, per the ticket's mandatory inspection step:

- **Sales → Invoices, live**: confirmed `tax_amount` renders as a flat "Tax Rp 20.000" line on Invoice Detail — no rate, code, or name shown anywhere, the exact "just a typed number" state this design changes.
- **Purchase → Orders, live**: confirmed zero tax presence anywhere — columns are Document Number / Supplier / Date / Status / Receiving Progress / Total Amount only; Purchase's own sub-nav is just Orders / Goods Receipts (no Purchase Invoice yet).
- **Master Data, live**: confirmed seven existing tabs (Items, Suppliers, Customers, Warehouses, Item Groups, UOMs, Chart of Accounts) — no Taxes tab, despite `TaxController` being fully built server-side. This is the concrete, observed gap §8's navigation recommendation is grounded in, not an assumption.
- **Company Information**: no frontend route exists (`/companies` unmapped in `router.tsx`) — could not be inspected live as the ticket's own list anticipated; noted honestly rather than glossed over.
- **Journal Entries / Accounting Reports**: reconfirmed unchanged from every prior sprint's own grounding — `2100 Tax Payable` is already a real, posted-to account, ready to keep receiving calculated (not typed) amounts with zero changes to how it's read.
- No console errors observed during inspection; no interaction attempted a write.

---

## Open Questions

1. **Purchase has no Invoice/Bill document type yet** — this design's Purchase-side reuse is a "when it's built, call `TaxService` the same way" contract, not a concrete integration point the way Sales is.
2. **Whether `tax_mode` (Exclusive/Inclusive) belongs on the document or as a per-`Tax`-record default** — this design treats it as a document-level input (mirroring `tax_id` itself being document-level, §5), but doesn't fully resolve whether a `Tax` record should carry its own default mode. Flagged for reviewer input.
3. **Company-level default Tax** (pre-filling new documents) is a natural convenience once `Company` gains its own tax-registration concept (§9) — not built now.

Stopping here — no code, no migrations, no tests, no frontend pages. Waiting for architectural review.
