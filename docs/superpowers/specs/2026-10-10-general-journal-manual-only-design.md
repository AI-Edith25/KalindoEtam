# General Journal — Manual-Only Design

Status: approved for planning. Scope: **Finance > General Journal** (`JournalEntryListPage`, route `/finance/general-journal`) and its read-only mirror in Reports > Journal List (`GeneralJournalPanel`, `journalType=general_journal`). Nothing else.

## 0. Problem

General Journal today lists **every** `journal_entries` row — manual entries created via "New Journal Entry" *and* every entry auto-posted by the Accounting Engine on behalf of Official Receipt (OR / `ReceiptEntry`), Invoice, Credit Note, Debit Note, Payment Allocation, and the Print Ledger opening-balance import. There is no way in the UI to see "manual only" — the Reference Type filter only offers `Invoice` / `Receipt Entry` / `Payment Allocation` (no "Manual" option), and leaving it unset shows everything mixed together.

The user wants General Journal to be **pure manual data entry** — it must never show entries that were pulled in from OR or any other module.

## 1. The signal: `reference_type IS NULL`

Confirmed by reading the code, not assumed:

- `StoreJournalEntryRequest` (the only backend entry point behind the "New Journal Entry" form) validates only `posting_date`, `description`, `lines[].chart_of_account_id/debit/credit/description`. Its own docblock: *"reference_type/reference_id are not accepted here; those are set internally by AccountingService on behalf of a business module... never by a direct API call."*
- Every system-generated entry (OR, Invoice, CN, DN, Payment Allocation, the Print Ledger opening-balance import) is created directly via `JournalEntry::query()->create([...])` or `JournalEntryService::create()` called from inside that module's own service — always with a non-null `reference_type`.
- There is no code path where a manual entry gets a `reference_type`, or a system-generated entry gets `reference_type = null`.

So `reference_type IS NULL` is an exact, no-exceptions predicate for "created manually through General Journal."

## 2. The one choke point

`JournalEntryController@index` and `@export` both go through `JournalEntryService::list()`/`listAll()` → `JournalEntryRepository::search()`/`searchAll()` → the shared `JournalEntryRepository::filteredQuery()`. Confirmed by grep: no other feature in the codebase calls this repository's `search`/`searchAll`/`filteredQuery`. The two frontend consumers (`JournalEntryListPage`, `GeneralJournalPanel`) both call `fetchJournalEntries`/`exportJournalEntries`, which hit exactly this endpoint. (`JournalEntryPrintPage` also calls `fetchJournalEntries`, but only ever with a specific `ids` filter for rows the user already selected from the now-manual-only list — never a source of non-manual rows.)

`filteredQuery()` gets one unconditional addition:

```php
->whereNull('reference_type')
```

Not a filter option, not a default — unconditional. This is the entire backend change.

## 3. Filters that stop making sense, and are removed

Once every row has `reference_type = null`:

- **Reference Type filter** (`Invoice`/`Receipt Entry`/`Payment Allocation`) — selecting any of these now always returns zero rows. Removed from `JournalEntryFiltersBar`, removed from `IndexJournalEntryRequest`'s validated fields, removed from `filteredQuery()`'s own `reference_type` branch (now redundant with the hard filter anyway).
- **Branch filter** — implemented today as `whereHasMorph('referenceDocument', [...])`, which only ever matches a system-generated entry (manual entries have no `referenceDocument`; confirmed the manual creation form has no branch field anywhere in `JournalEntryEditorPage` or `StoreJournalEntryRequest`). Against manual-only rows this filter is permanently dead (always zero results when set) — removed from the filter bar and from `filteredQuery()`, rather than shipped as a silently-broken control.

Kept unchanged: **Status**, **Account**, **Date Range**, **Search** — all still operate correctly against manual entries.

## 4. UI cleanup that follows directly

- `JournalEntryListPage` header description ("Every posted debit/credit, system-generated or manually posted.") is now false — replaced with copy describing manual-only entry.
- **Reference Type** and **Reference Number** columns in both `JournalEntryListPage` and `GeneralJournalPanel`'s tables would always read "Manual" / "—" on every row — removed (user confirmed).
- `JournalEntryFilterValues` type, `emptyJournalEntryFilters`/`hasActiveJournalEntryFilters` (`journalEntryFilters.ts`), and `JournalListPage`'s `generalJournalFilters`/`setGeneralJournalFilters` wiring drop `referenceType`/`branchId`.

## 5. Explicitly not touched

Accounting Engine, posting logic for OR/Invoice/CN/DN/Payment Allocation, the Print Ledger opening-balance import, and every report that reads `journal_entries` through a *different* repository/service — General Ledger (Reports), Trial Balance, Profit & Loss, Balance Sheet, Cash Flow, Journal List's Cash Book/Sales Journal/Purchase Journal tabs. None of those go through `JournalEntryRepository`, so they keep seeing every entry exactly as today. Only General Journal's own list/export narrows.

## 6. Test impact

`tests/Feature/JournalEntryExportTest.php` currently *proves* an `reference_type = invoice` entry appears in General Journal's index and export (that's the bug). Both tests are rewritten to prove the opposite:

- A manual entry (`reference_type = null`) appears in the index/export.
- An entry with `reference_type = invoice` does **not** appear in either, regardless of filters.
- (The existing Branch-filter test scenario is dropped along with the Branch filter itself — there is nothing left for it to prove.)

## Open questions

None — this is a contained, single-choke-point change confirmed against the actual code paths, not a guess.
