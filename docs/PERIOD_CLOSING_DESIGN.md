# Period Closing — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests, no frontend pages until approved.

Frozen inputs this design must not modify: **Accounting Engine, Accounts Receivable, Receipt Entry, Payment Allocation, Credit Note, Debit Note, General Ledger, Trial Balance, Profit & Loss, Balance Sheet, Cash Flow**. This design proposes **new tables** (`fiscal_years`, `accounting_periods`) — there is no existing table to extend, unlike every prior report design, which could reuse `report_account_mappings`. "No schema changes" in every prior sprint meant *don't touch the frozen accounting tables*; that discipline is kept here too — `journal_entries`, `journal_entry_lines`, and `chart_of_accounts` gain **zero new columns**. Nothing in this document is migrated or implemented — it is a proposal, in the same code-block-sketch style every prior `*_DESIGN.md` already uses.

## 0. What already exists (grounding)

Confirmed by inspecting the running app (Chrome — Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet, Cash Flow, all under "Accounting Reports") and reading `JournalEntryService`/`JournalEntry`/`Documentable`/`HasAuditTrail`/`DocumentTimeline`/`RolePermissionSeeder`/`User` before writing anything below:

- **`journal_entries.posting_date` is the only date any accounting figure is ever computed from** — every report (`GeneralLedgerRepository`) filters/aggregates by it. Live data already spans multiple months and even a future-dated entry relative to "today" (07 Feb 2026, three entries 18–19 Jul 2026, one 07 Dec 2026, confirmed via Journal Entries List) — Period Closing must validate the *specific date range being closed*, not "everything before some single global cutoff."
- **`JournalEntryService::create()`/`update()`/`post()`/`reverse()` (existing, unchanged) are the only places `journal_entries` is ever written** — confirmed by reading the class fully. `reverse()` never backdates: it always posts the offsetting entry at `now()->toDateString()`, calling `create()` + `post()` internally. This means gating `JournalEntryService` once is sufficient to gate *every* business module (Invoice, Credit Note, Debit Note, Receipt Entry, Payment Allocation all route through `AccountingService` → `JournalEntryService`, confirmed by the routes file's own comment: *"Invoice/Receipt Entry -> Accounting Service -> Journal Entry -> General Ledger"*) — no other service needs to change.
- **`Documentable` (existing, unchanged)** gives every transactional document a `DRAFT → SUBMITTED → CANCELLED` lifecycle. `JournalEntry::cancel()` already overrides this to throw — `reverse()` is its only correction path (§0 of the Sprint 15A design already documented this). Only `SUBMITTED` entries are ever read by any report (`GeneralLedgerRepository::baseQuery()` filters `status = SUBMITTED` by default) — a `DRAFT` entry is invisible to every figure this whole module computes.
- **`DocumentTimeline` + `DocumentTimelineService::record($subject, $action, $description, $properties)` (existing, unchanged) is a *generic* polymorphic audit log** — `$subject` can be any Eloquent model, not only a `Documentable` one. Every `Documentable` model already uses it for "created"/"submitted"/"cancelled" history. This is exactly the mechanism "Reopening should leave a complete audit trail" needs — reused directly, not reinvented.
- **`HasAuditTrail` (existing, unchanged)** already gives any model `created_by`/`updated_by`/`deleted_by` via `AuditableObserver`. Reused for who *set up* a Fiscal Year/Period record; **Closed By/Reopened By need their own dedicated columns** (§1) since those are distinct lifecycle events, not row authorship.
- **Role/Permission (Spatie, seeded — `RolePermissionSeeder`) exists but is enforced nowhere in this codebase today** — confirmed by grep: every `FormRequest::authorize()` in the entire `app/Http/Requests` directory returns `true` unconditionally, and no Controller calls `->can()`/`Gate::`. An `Admin` role is seeded with every `{module}.{action}` permission. **"Only authorized administrators should be able to reopen a closed period" is this codebase's first real enforcement point for this dormant system**, not a reuse of an existing pattern — flagged explicitly, not silently assumed to already work.
- **`Company.fiscal_year_start` (existing, unchanged)** is already the live anchor Trial Balance/Profit & Loss/Balance Sheet/Cash Flow's "This Fiscal Year" preset all resolve against. This design's own `fiscal_years` table (§1) is the natural, durable home for that concept — the single date on `Company` becomes "the current fiscal year's start," while `fiscal_years` holds the full history, the same relationship the frontend's own preset already implicitly wants but has no backing table for today.
- **"Settings" is not a built module** — confirmed live and in `router.tsx`: the sidebar shows a "Settings" item, but no `/settings` route exists; the code comment states it "falls through the router's catch-all... until its module is built." There is no live "Administration" area to place anything under today.
- **Chart of Accounts — the closest existing precedent for "accounting setup/administration data" — lives under Master Data (`/master/chart-of-accounts`)**, not a separate Settings/Administration silo. This codebase's established convention colocates setup/administrative accounting concerns with their domain rather than a generic catch-all section.
- **Accounting Reports already has six tabs** (Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet, Cash Flow) in `accountingSectionNav` — confirmed live. This design proposes Period Closing as a seventh tab, the same growth pattern the section has followed six times already, though — unlike the six reports — it is this section's first *write*-capable page (§5).

---

## 1. Period Management Architecture

```
Fiscal Year (1) ──< (many) Accounting Period
```

```php
Schema::create('fiscal_years', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('company_id')->constrained('companies'); // scoped per company from day one — §6
    $table->string('name');                                       // e.g. "FY2026"
    $table->date('start_date');
    $table->date('end_date');
    $table->timestamps();
    $table->softDeletes();
    $table->unique(['company_id', 'start_date']);
});

Schema::create('accounting_periods', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('fiscal_year_id')->constrained('fiscal_years');
    $table->string('name');                                       // e.g. "July 2026"
    $table->date('start_date');
    $table->date('end_date');
    $table->string('status');                                     // PeriodStatus: 'open' | 'closed'
    $table->foreignUuid('closed_by_id')->nullable()->constrained('users');
    $table->timestamp('closed_at')->nullable();
    $table->foreignUuid('reopened_by_id')->nullable()->constrained('users');
    $table->timestamp('reopened_at')->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->unique(['fiscal_year_id', 'start_date']);
});
```

```php
enum PeriodStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}
```

**Why exactly two states, per the ticket's own instruction not to add more without strong justification:** a `Documentable` document (Journal Entry, Invoice, Credit Note...) already has a real 3-state lifecycle (`DRAFT → SUBMITTED → CANCELLED`) because it is *authored* — someone builds it up before committing it. An Accounting Period is not authored; it is a boolean gate over a date range — either new postings into it are allowed, or they are not. There is no accounting justification in this codebase for a third state (no "pending closing" review step exists anywhere else — see §3's rejection of an approval workflow). Reusing `DocumentStatus` itself was considered and rejected: `DRAFT`/`CANCELLED` have no meaning for a period (a period is never "drafted" or "cancelled," only opened or closed), so a dedicated two-case enum is the honest fit, not a repurposed one.

**No new column on `journal_entries`.** The period a posting belongs to is *derived* from its existing `posting_date` against `accounting_periods.start_date`/`end_date` at check time — never a stored `accounting_period_id` foreign key on every journal line. This is the same "reuse an existing field rather than add a relation" discipline `BALANCE_SHEET_DESIGN.md`/`CASH_FLOW_DESIGN.md` already applied to `opening_balance`/`ending_balance` — it keeps the frozen `journal_entries` table completely untouched.

### The enforcement point: `PeriodLockService`

```php
class PeriodLockService
{
    public function assertOpen(string $postingDate, ?string $companyId = null): void
    {
        $period = $this->accountingPeriodRepository->containing($postingDate, $companyId);

        if ($period?->status === PeriodStatus::CLOSED) {
            throw new BusinessException("Posting date {$postingDate} falls in a closed accounting period ({$period->name}) — postings are not allowed.");
        }
        // No period record covers this date at all -> not blocked. See the "fail open" decision below.
    }
}
```

**Fail-open, not fail-closed, when no period record exists for a date.** Two options were considered: (a) block every posting unless an explicit, open `AccountingPeriod` row already covers its date, or (b) only block when a period row *explicitly exists and is closed*; the absence of any period row imposes no restriction. **Choice: (b).** Every business module in this app already posts through `JournalEntryService` today, for dates going back to February 2026 (§0) — no `AccountingPeriod` row exists yet for any of that. Choosing (a) would instantly break every existing document module the moment this feature merges, for a company that hasn't yet set up its period calendar. Choice (b) is the same "wired but dormant until actively used" posture Branch/Company filters already have throughout this app (`GENERAL_LEDGER_DESIGN.md §0`) — Period Closing has zero effect on anything until a user actually closes a period.

**Insertion points, minimal and additive — no rewrite of `JournalEntryService`:**

```php
// JournalEntryService::create() — first line, same "assert-then-proceed" shape every
// existing guard (assertHasEnoughLines, assertBalanced, assertDraft) already uses.
$this->periodLockService->assertOpen($data['posting_date']);

// JournalEntryService::post() — the load-bearing check: this is the only moment an
// entry actually starts affecting any report (only SUBMITTED entries are ever read).
// A period could close *between* create() and post(), so create()'s check alone is
// not sufficient — post() re-validates independently.
$this->periodLockService->assertOpen($journalEntry->posting_date->toDateString());

// JournalEntryService::update() — only when posting_date is present in $data (a draft
// being re-dated into a closed period should fail fast, the same UX reasoning as create()).
if (isset($data['posting_date'])) {
    $this->periodLockService->assertOpen($data['posting_date']);
}
```

Four call sites total (`create`, `update`, `post`, and `delete` is intentionally **not** gated — deleting a draft never touches the ledger and can never resurrect a closed period's figures, so blocking it would be pure friction with no accounting benefit). Every one is a single added line, the same shape as the guards already in the class — this is the entire "insertion," not a redesign.

**Why this one gate is sufficient for the whole system:** Invoice, Credit Note, Debit Note, Receipt Entry, and Payment Allocation never call `journal_entries`-writing code of their own — they all go through `AccountingService` → `JournalEntryService::create()`/`post()` (confirmed §0). Gating `JournalEntryService` once means an Invoice submitted with an `invoice_date` inside a closed period already fails — the `BusinessException` `JournalEntryService::create()` throws propagates straight up through `InvoiceService::submit()` — with **zero changes to any of those five frozen services**.

---

## 2. Closing Workflow

**The single most important decision in this design: Period Closing posts no journal entries. It is a lock, not an accounting operation.**

```
PeriodClosingService::close(AccountingPeriod $period, User $user): AccountingPeriod
  1. assertOpen($period)                        — cannot close an already-closed period
  2. run the validation checklist (§3)            — throws, listing every failed check, if any fail
  3. DB::transaction:
       a. update period: status = CLOSED, closed_by_id = $user->id, closed_at = now()
       b. DocumentTimelineService::record($period, 'closed', properties: [validation results snapshot])
  4. return $period->fresh()
```

No step here calls `JournalEntryService::create()`. Why this matters, concretely:

- Every prior design in this module (`PROFIT_LOSS_DESIGN.md` Open Question 2, `TRIAL_BALANCE_DESIGN.md` Open Question 2, `BALANCE_SHEET_DESIGN.md §6`) flagged the same gap — no period-closing exists, so `3100 Retained Earnings` has never received a posting, and Balance Sheet's own design instead *re-derives* prior periods' profit on the fly via `ProfitLossService`. **This design deliberately does not resolve that gap by posting real closing entries at the monthly/period level.** Doing so would mean re-touching `BalanceSheetService` (recomputing Retained Earnings differently once real postings exist) and `JournalEntryService` (a new kind of system-generated entry) — exactly the redesign the ticket forbids ("Do not redesign the accounting engine").
- Instead, this sprint's Period Closing is purely a **governance control**: it prevents new postings and edits inside a date range once staff consider it "done," the same real-world practice of a monthly "soft close." A separate, later **Year-End Closing** (§8, explicitly out of scope this sprint, exactly as the ticket's own Future Expansion section anticipates) is the concept that would eventually post real closing entries — and only that future feature needs to touch `BalanceSheetService`'s Retained Earnings math, not this one.
- This keeps Balance Sheet, Cash Flow, and every other report **completely unchanged** by this sprint — the clean separation the ticket explicitly requires (see below).

### Closing effects — what becomes restricted

- Any `journal_entries` row whose `posting_date` falls inside `[period.start_date, period.end_date]` becomes unpostable/uneditable from the moment of closing, enforced by the same `PeriodLockService::assertOpen()` guard already wired into `create()`/`update()`/`post()` (§1) — no new mechanism, the existing guard simply now finds a `CLOSED` period where before it found none.
- A `DRAFT` entry that already existed with a date inside the now-closed period is not deleted or altered — it simply can never be posted (`post()`'s check fails) or re-dated into the closed range (`update()`'s check fails). It remains visible as an orphaned draft, exactly the same "nothing vanishes silently" principle every report in this module already follows for unmapped accounts.
- `closed_by_id`/`closed_at` record who closed it and when (§0's `HasAuditTrail` pattern extended with dedicated columns, since this is a distinct lifecycle event, not row authorship).

### Closing a period vs. generating financial reports — the required distinction

These are two independent, non-overlapping concerns, by construction:

| | Closing a period | Generating a report |
|---|---|---|
| **What it touches** | `accounting_periods` (one row's status) | Nothing — every report is already a pure read model over `journal_entries` |
| **When it runs** | Once, deliberately, by a user | Every time a report page loads |
| **Effect on the other** | Blocks *future* postings into the period; changes nothing about figures already posted | None on closing — a report simply reflects whatever is currently posted, closed period or not |

A closed period's Trial Balance/Profit & Loss/Balance Sheet/Cash Flow for that date range renders **identically** before and after closing — because closing never touches `journal_entries`, only gates future writes to it. This is the direct, structural proof of the required separation: reports read one table, closing writes to a different one, and the only interaction between them is that closing *constrains what the read tables can ever contain going forward*.

---

## 3. Closing Validation

Ticket's candidates, each evaluated against what is *actually* breakable in this codebase — "only include validations that are necessary":

1. **No Draft Journal Entries dated within the period.** A `DRAFT` entry sitting inside the period being closed is a genuine, concrete risk: if someone posts it later (after the period is considered final), it silently changes a "done" period's figures. Check: `journal_entries` rows with `status = draft` and `posting_date` between the period's bounds — one new `WHERE`-scoped count query on the existing table, the same aggregate-query shape every report already uses.
2. **Accounting equation consistency.** Reuses `BalanceSheetService::summarize(['as_of_date' => $period->end_date])['is_balanced']` **directly** — not re-derived. If Assets ≠ Liabilities + Equity as of the period's end date, closing is blocked. This is the exact guarantee `BalanceSheetService` already computes and logs a warning for (`BALANCE_SHEET_DESIGN.md §8`); Period Closing's validation is reading that existing boolean, never recomputing the identity itself.
3. **Cash Flow consistency.** Reuses `CashFlowService::summarize(['date_from' => $period->start_date, 'date_to' => $period->end_date])['is_balanced']` directly, the same reuse principle — if Opening Cash + Net Movement ≠ Closing Cash for the period, block.
4. **Sequential closing — the prior period must already be closed.** You cannot close March while February is still open: March's opening balances are only trustworthy if February can no longer change. Check: the immediately preceding `AccountingPeriod` (by `start_date`, same or prior fiscal year) has `status = CLOSED`. The very first period ever has no predecessor and trivially passes.

**Deliberately excluded, and why (avoiding overengineering, per the ticket's own instruction):**

- **Cross-checking upstream documents (unposted Sales Orders, un-invoiced Deliveries, etc.).** Out of scope: those aren't accounting postings. `journal_entries` is already the accounting engine's single source of truth — every module that affects the ledger routes through it (§0/§1), so validating `journal_entries` alone is sufficient without also auditing every upstream document module individually.
- **A multi-step approval workflow before closing.** `ApprovalFlow` exists in this codebase but has zero real consumers — Sprint 14A/14B already explicitly deferred building the first real `ApprovalFlow` consumer for Debit Note, on the stated grounds that "the project doesn't yet have a shared Approval Workflow Engine" and the user doesn't want a module-specific one built ahead of it. The identical reasoning applies here: closing is a pass/fail checklist run by one authorized user, not a multi-person sign-off chain.
- **"Report consistency" beyond the two identities above.** Trial Balance's own Balanced/Imbalance figure is arithmetically the same statement as Balance Sheet's Total Assets = Total Liabilities + Equity (`TrialBalanceService::placeBalance()` and `BalanceSheetService`'s identity both reduce to the same double-entry fact) — checking Balance Sheet's `is_balanced` already covers it; a third, separate Trial-Balance-specific check would be redundant, not additional safety.

---

## 4. Reopen Period

```
PeriodClosingService::reopen(AccountingPeriod $period, User $user): AccountingPeriod
  1. assertClosed($period)
  2. authorize: $user->hasRole('Admin')                — Spatie, this codebase's first real
                                                            enforcement of its dormant Role/Permission
                                                            system (§0) — checked in the FormRequest's
                                                            authorize() the same way every other request
                                                            in this app already structures the check,
                                                            just the first one that actually returns
                                                            something other than `true`.
  3. assertIsMostRecentlyClosed($period)               — mirrors §3.4's own "close in order" rule in
                                                            reverse: you may only reopen the most
                                                            recently closed period (no closed period
                                                            chronologically after it), for the identical
                                                            reason later periods shouldn't rest on
                                                            now-mutable earlier data.
  4. DB::transaction:
       a. update period: status = OPEN, reopened_by_id = $user->id, reopened_at = now()
                                                            — closed_by_id/closed_at are deliberately
                                                            PRESERVED, not cleared (see below)
       b. DocumentTimelineService::record($period, 'reopened', properties: [...])
  5. return $period->fresh()
```

**Why `closed_by_id`/`closed_at` are preserved through a reopen, not cleared:** the `accounting_periods` row's own columns always show the *latest* close/reopen pair (exactly what the Closing History list in §5 needs — one row per period, current state). The **full chronological history** of every close → reopen → close cycle (potentially more than one, over a period's lifetime) lives in `DocumentTimeline` via step 4b, reusing the existing, generic mechanism rather than growing `accounting_periods` into an event log itself. This is the same split every `Documentable` model already has: the row shows current state, `DocumentTimeline` shows the full story.

If a reopened period is closed again, step 3a's `close()` workflow runs again and simply **overwrites** `closed_by_id`/`closed_at` with the new event — the prior closing is still fully recoverable from `DocumentTimeline`, never lost.

---

## 5. Closing History Design

One row per `AccountingPeriod` (not per event — the full event-by-event history lives in `DocumentTimeline`, reachable if ever needed, but the ticket's own column list — *Status, Closed By, Closed At, Reopened By, Reopened At* — describes current state, not a log). Reuses the same `DataTable` component every list page in this app already uses.

**Columns**: Fiscal Year, Accounting Period, Status (badge — green "Open" / gray "Closed", the same `Badge` component and color convention Trial Balance/Balance Sheet/Cash Flow's own Balanced/Imbalance pill already establishes), Closed By, Closed At, Reopened By, Reopened At (the last four render `—` when not applicable, the same convention Trial Balance's Debit/Credit columns already use for zero values).

**Filters**: Fiscal Year (select, since a company may have several), Status (All/Open/Closed) — same `FilterPanel` shape every report's filter bar already uses, no new filter primitive.

**Row actions**: a "Close" action on Open rows, a "Reopen" action on the single most-recently-closed row (visible to any user for feedback, but only succeeds server-side for an Admin — the same "button visible, server enforces" pattern already implicit in every other permission-gated action in this app, since no frontend permission-based rendering exists yet per `layouts/navigation.ts`'s own comment: *"Static for Frontend Sprint 1 — permission-based rendering comes later"*). Clicking Close surfaces the §3 validation checklist result inline (which checks passed/failed) before confirming — the same "show what happens before an irreversible action" discipline this app already applies to Credit Note/Debit Note submission.

**No separate detail page for this sprint.** A full per-period timeline view (every close/reopen event via `DocumentTimeline`) is a natural future addition (the data already exists, §4) but isn't needed to satisfy the ticket's own column list — deferred rather than built speculatively, per YAGNI.

---

## 6. Navigation Recommendation

**Recommendation: under Accounting (a seventh tab in the existing `accountingSectionNav`), not Administration.**

Reasoning, grounded in what's actually live in this codebase (§0):

1. **"Administration"/"Settings" is not a built section today** — the sidebar shows a placeholder that falls through to the dashboard; there is no live area to place anything under without first scaffolding an entire new top-level module, which nothing in this sprint asks for and which no other page would yet populate.
2. **The closest existing precedent — Chart of Accounts — already lives under Master Data (domain-specific), not a generic Settings silo.** This is this codebase's own established convention for "administrative accounting setup data": colocate with the domain, don't centralize into a catch-all.
3. **The audience is identical to the six existing tabs**: accounting/finance staff, the same people who already use Journal Entries through Cash Flow. There is no evidence in this codebase that "administration" and "accounting configuration" are staffed or permissioned differently — the dormant Role/Permission system (§0) has exactly one seeded role (`Admin`) with every permission, not yet a finance-vs-admin split.
4. **No existing precedent in this app segregates read pages from write pages into different sidebar sections** — Sales' own section mixes Sales Order List (read) with the Sales Order Editor (write); Purchase does the same. Accounting Reports gaining its first write-capable tab is consistent with how every other module in this ERP is already organized, not an exception needing a new home.

Route: `/accounting/period-closing`, seventh entry in `accountingSectionNav`, same `SectionNav` component, no new top-level sidebar item.

**Open call, not decided here**: whether the sidebar label "Accounting Reports" should be renamed now that it contains a non-report page. Flagged, not resolved — the prior UX-only rename (Sprint 17A, "Accounting" → "Accounting Reports") was its own small, dedicated ticket, not bundled into a feature sprint; the same discipline suggests this is a separate decision for the product owner, not something this design should force.

---

## 7. UI Design

Consistent with Trial Balance/Profit & Loss/Balance Sheet/Cash Flow's own established shell:

- Same `PageHeader` ("Period Closing" title, description in the same "A read-only report of..." phrasing pattern this module already uses, adapted: *"Manage fiscal years and accounting periods — closing a period locks its posting date range against new or edited Journal Entries; reports themselves are never affected."*) + `ActionBar` (Refresh; a "New Fiscal Year" action, permission-gated the same way).
- Same filter-row-then-table layout, same `flex flex-col gap-4` page spacing every report page already uses.
- `DataTable` (existing shared component) for the Closing History list (§5), not a bespoke table — same column/row/badge conventions as every other list page in this app (Journal Entries List, Trial Balance, etc.).
- Close/Reopen are modal-confirmed actions (existing `Dialog`-style confirmation already used elsewhere for irreversible actions like Credit Note submission), not full-page navigations — a period row's action stays inline, consistent with how Journal Entry's own "Reverse" action is triggered from its detail page rather than a separate route.

**User flow**: Accounting Reports → Period Closing tab → see Closing History table → pick an Open period → Close (validation checklist shown, confirm) → row updates to Closed in place → (Admin only) Reopen the most recent Closed row if needed, same inline pattern.

No implementation — described for review only.

---

## 8. Future Expansion Strategy

- **Year-End Closing** — builds strictly on top, once every `AccountingPeriod` under a `FiscalYear` is `CLOSED`. Adds: a `status` column on `fiscal_years` itself (same two-case `PeriodStatus`-shaped pattern, not a new vocabulary), and a new `YearEndClosingService` that (a) posts real closing journal entries — Revenue/Expense accounts zeroed into `3100 Retained Earnings` — through the existing, unchanged `JournalEntryService` (the first and only system-generated entry of its kind), and (b) marks the fiscal year closed. This is the point where `BalanceSheetService`'s Retained Earnings derivation (`BALANCE_SHEET_DESIGN.md §6`) would finally start reading a real posted balance instead of re-deriving prior years' P&L on the fly — a change scoped entirely to that future sprint, requiring **zero changes to this sprint's `AccountingPeriod`/`PeriodLockService`/`PeriodClosingService`**.
- **Multi Fiscal Year** — already native. `fiscal_years` is already a table designed to hold many rows per company; nothing in this design assumes exactly one. `Company.fiscal_year_start` remains the live anchor for "current" fiscal year (used by every report's own preset resolution, unchanged), while `fiscal_years` holds the full addressable history — the same "live anchor plus full history table" relationship, not a conflict.
- **Multi Company** — already native. `fiscal_years.company_id` (and `accounting_periods` transitively through it) is scoped per company from the very first migration sketch in §1, the same "wired but dormant" posture every `branch_id`/`company_id` filter already has throughout this app (`GENERAL_LEDGER_DESIGN.md §0`) — inert for a single-company SME today, correct the moment a second company exists, with no schema change required later. `PeriodLockService::assertOpen()` already accepts an optional `$companyId`.

---

## 9. Browser Verification Report

Performed against the running app (`php artisan serve` + `vite dev`) before writing this design, per the ticket's mandatory inspection step:

- **Journal Entries, live**: 6 entries confirmed, posting dates spanning 07 Feb 2026 through 07 Dec 2026 — grounding §0's point that real data already spans multiple periods, several out of strict chronological order (a Dec-dated Debit Note posted alongside July-dated entries), the concrete case Period Closing's sequential-close rule (§3.4) and per-period validation (not a single global cutoff) are designed around.
- **General Ledger, live, unfiltered**: full 16-account chart confirmed, Accounts Receivable ending balance `Rp 235.000` — reconfirms the underlying ledger this whole module reads is unchanged since Sprint 19B.
- **Trial Balance, live, This Month**: 4 non-zero accounts, `Rp 175.000` balanced — reconfirms Trial Balance's own read-model behavior, exactly what this design's §2 promises stays unaffected by closing.
- **Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet, Cash Flow all confirmed live under the same six-tab `accountingSectionNav`** — the proposed seventh "Period Closing" tab follows the identical, already-six-times-proven growth pattern; nothing about the module's structure needs to change to accommodate it, and no `/settings` route exists to place it under instead (§6).
- No console errors or failed network requests observed during inspection; no interaction attempted a write (this sprint touches no create/edit/delete surface, and none was exercised).

---

## Open Questions

1. **Should Period Closing eventually gate document *dates* (Invoice date, Credit Note date) directly, not just Journal Entry `posting_date`?** Not needed today — every module that affects the ledger already routes through `JournalEntryService` (§0/§1), so gating it once is already complete coverage. Flagged only in case a future module ever posts to `journal_entries` through a path that bypasses `JournalEntryService` — none exists today.
2. **Reversals of entries whose original period is now closed.** `JournalEntryService::reverse()` always posts into *today's* period, never the original's (§1) — this is correct standard practice (never edit history, always correct forward), but worth confirming explicitly with the reviewer as intended behavior, not an oversight.
3. **Whether `3100 Retained Earnings` should start receiving real postings at per-period closing or only at Year-End Closing.** This design resolves it definitively as **Year-End Closing only** (§2/§8) — the direct answer to the gap every prior report design (`PROFIT_LOSS_DESIGN.md`, `TRIAL_BALANCE_DESIGN.md`, `BALANCE_SHEET_DESIGN.md`) has flagged and deferred.

Stopping here — no code, no migrations, no tests, no frontend pages. Waiting for architectural review.
