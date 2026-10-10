# Customer Receivable Categories (C / PK / PL) — Design

Status: approved. Scope: Customer master data, Payment Voucher (PaymentEntry), Official Receipt (ReceiptEntry).

## 0. Problem

Three kinds of receivables share the Customer master today but need to post to three different Chart of Accounts control accounts (seeded 2026-10-09, `ChartOfAccountsSeeder`):

| Category | Code prefix | COA account | Meaning |
|---|---|---|---|
| C | `C-` | 112.01 Piutang Usaha | Normal trade customer — Invoice → OR → Payment Allocation, unchanged |
| PK | `PK-` | 112.02 Piutang Karyawan | Employee receivable — no Invoice, ever |
| PL | `PL-` | 112.03 Piutang Lain-lain | Other receivable — no Invoice, ever |

PK/PL customers never get an Invoice. Their balance is created by a Payment Voucher (advance paid out) and settled by an Official Receipt (repayment received in) — pure GL postings, no AR-subledger/invoice-matching involved.

## 1. Customer gets a `receivable_category`

`customers.receivable_category` (`C`|`PK`|`PL`, default `C` — every existing customer is unaffected). Set once at creation, **locked afterward** (never accepted by `UpdateCustomerRequest` — changing it would orphan journal history already posted against the old account). Drives:
- Which `NamingSeries` the code suggestion/generation uses (`customer`/`customer_pk`/`customer_pl` — three independent counters, continuing from whatever's already in `customers.customer_code` for that prefix today, same bump-past-existing-data approach as the 2026-10-01 Customer code fix).
- Which COA account (112.01/.02/.03) PV/OR post to for that customer.

A single `App\Enums\ReceivableCategory` backed enum is the one place category → account code / naming-series document type / code prefix is mapped (`accountCode()`, `namingSeriesDocumentType()`, `codePrefix()`), used by Customer, PaymentEntry, and ReceiptEntry — never duplicated.

Credit Limit and Terms of Payment are hidden on the Customer form for PK/PL (confirmed with user — meaningless for a category that never has an Invoice/credit check).

## 2. Payment Voucher — new Payment Type

New `PaymentEntryType::CUSTOMER_ADVANCE`. New nullable `payment_entries.customer_id` (sibling to `supplier_id`). New "Payment Type" option in the existing Supplier / General Expense / Mixed selector: **"Terhadap Customer (PK/PL)"** — the Customer picker only lists customers whose category is PK or PL (a `receivable_category` filter on the existing `/customers` list endpoint, not a new endpoint). No Outstanding Payables step — there is nothing to allocate against.

`PaymentEntry::journalLines()` new branch: `Dr {customer.receivable_category->accountCode()} / Cr cash_account` — straight to Piutang Karyawan/Lain-lain, bypassing 1250 (Advance to Suppliers) entirely.

## 3. Official Receipt — same type, branches on the customer

No new `ReceiptEntryType` — `CUSTOMER` stays the only "money from a customer" type. `ReceiptEntry::journalLines()`'s existing `CUSTOMER` branch now reads the selected customer's category:
- `C` (unchanged): `Dr cash / Cr 1150` (Unapplied Customer Payments), later allocated to an invoice via Payment Allocation.
- `PK`/`PL`: `Dr cash / Cr {customer.receivable_category->accountCode()}` directly — no 1150, no invoice allocation, nothing to allocate (there is no Invoice).

Frontend: the "Outstanding Invoices" allocation card on the Official Receipt form is hidden once the selected customer's category isn't `C` (nothing would ever show there — `accounts_receivable` rows only exist per-Invoice).

## 4. Explicitly not building

- No new "balance per PK/PL customer" report/screen. General Ledger (Reports), filtered to account 112.02/112.03, already shows every PV/OR line with the customer's name in its description — sufficient to read a running balance without a new screen.
- No "change category after creation" flow.
- No Credit Limit / Terms of Payment for PK/PL (confirmed with user).

## 5. Test impact

- `CustomerCodeGenerationTest`-style coverage for PK/PL code suggestion (continuing after the last existing PK-/PL- code, same as the existing C- test's `test_series_correction_starts_after_the_real_existing_range`).
- New `PaymentEntryCustomerAdvanceTest`: journal posts to the right Piutang account per category, picker/validation rejects a `C` customer for `customer_advance`.
- Extend Receipt Entry coverage (new test alongside `ReceiptEntryOtherIncomeTest`): PK/PL customer skips 1150 and posts straight to its Piutang account; existing `C` customer behavior is proven unchanged (regression guard).

## Open questions

None — confirmed with user: Customer stays the single master table (no new entity), PV/OR both branch on the existing Customer record rather than adding new pickers, and PK/PL drop Credit Limit/Terms of Payment.
