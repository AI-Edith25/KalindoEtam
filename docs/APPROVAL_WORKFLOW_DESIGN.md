# Approval Workflow — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests, no frontend pages until approved.

Frozen inputs this design must not modify: **Accounting Engine, Tax Engine, Administration Module (Roles & Permissions, Audit Log), Dashboard, Period Closing, and every Documentable model's existing lifecycle (`document_number`/`status`/`submitted_at`/`cancelled_at`/`revision`)**. This design proposes **activating** the already-modeled but never-used `ApprovalFlow` entity as an optional, opt-in gate in front of the one shared `submit()` method every document type already calls — no new document status values, no schema change to any document table, no parallel approval mechanism.

## 0. What already exists (grounding) — the central discovery of this sprint

Confirmed by inspecting the running app (Chrome — Sales Orders, Purchase Orders, a live Draft PO's detail page, Journal Entries, Dashboard, Administration) and reading `ApprovalFlow`, `ApprovalStatus`, `Documentable`, `SalesOrderService`/`PurchaseOrderService`/`JournalEntryService`, and `PeriodLockService` in full before writing anything below:

- **`ApprovalFlow` is complete, dormant infrastructure — and its enum already matches this ticket's states almost exactly.** `App\Enums\ApprovalStatus` already has exactly three cases: `PENDING`, `APPROVED`, `REJECTED` — precisely the three non-Draft states this ticket asks for, already sitting in the codebase, unused. Grepping the entire `app/`, `routes/`, `database/` trees for `ApprovalFlow`/`ApprovalStatus` turns up exactly three files: the enum, the model, and one relation declaration in `Documentable` (`approvalFlows(): MorphMany`). **No controller, service, or migration touches it.** This is the same "fully-shaped, never activated" pattern this design series keeps finding (Tax, Role/Permission, Company, User) — except this time the entire state machine was already modeled correctly on the first try.
- **`Documentable::submit()` is the single, shared interception point** every document type already calls — confirmed by reading `SalesOrderService::submit()` and `PurchaseOrderService::submit()`, both of which run their own pre-checks (e.g. "cannot submit without items") and then call `$model->submit()`, the identical trait method `JournalEntry` also inherits. Its current body is one guard (`abort_if($this->status !== DocumentStatus::DRAFT, ...)`) plus a status flip to `SUBMITTED`. This is the **one place** an approval gate needs to be added for it to apply to every document type at once — not five separate places.
- **`DocumentStatus` (draft/submitted/cancelled) is frozen and does not need new cases.** A document's lifecycle status and its approval status are different questions — "is this document editable/submitted/cancelled" vs. "has an approver signed off on it" — and `ApprovalFlow` already models the second question as a *separate*, polymorphic, many-row entity (`approver_id`, `status`, `step`, `remarks`, `decided_at`) rather than a column on the document itself. Extending `DocumentStatus` with `PENDING_APPROVAL`/`APPROVED`/`REJECTED` would ripple into every `StatusBadge`, every list page's Status filter, and every *other* Documentable type (Invoice, Delivery, Credit Note, Goods Receipt, Stock Adjustment, Payment/Receipt Entry) that will never need approval — exactly the kind of scope creep this design series has consistently avoided. §2 below treats approval status as **derived from the latest `ApprovalFlow` row**, never written onto the document.
- **Journal Entry already distinguishes manual entries from system-generated ones, live, today** — confirmed in the running Journal Entries list: `JE-00007`/`JE-00008` show `Reference Type: Manual`, `Reference Number: —`, while every other row shows `Invoice`/`Credit Note`/`Debit Note` with a real reference. The list page already has a working "Reference Type" filter treating `Manual` as a first-class category. This is the exact, already-existing signal §3 uses to scope Journal Entry approval to manual entries only.
- **Journal Entry has a completely separate, already-enforced temporal gate — Period Locking — which this design must not touch or duplicate.** `PeriodLockService::assertOpen()` is called by `JournalEntryService` before every write (per its own docblock: *"everything that can ever write journal_entries routes through JournalEntryService, and JournalEntryService calls this before every write"*), checking whether the posting date falls in a `CLOSED` `AccountingPeriod`. This is a **when** question (is this calendar period still open for postings), completely orthogonal to approval's **who/whether** question (has an authorized person signed off on this document's content). Confirmed live in the backend log from Sprint 23B's own testing: a real `BusinessException` — *"Posting date 2026-01-15 falls in a closed accounting period... postings are not allowed"* — already fires independently of anything approval-related. Both gates can apply to the same Journal Entry submission; neither replaces the other; §3 keeps them fully separate.
- **A live Draft document's action bar is exactly `Edit / Submit / Delete`**, confirmed on `PO-00001`'s own detail page — plus an "Audit Information" card showing Created/Submitted/Cancelled timestamps. This is the concrete UI slot a future "Request Approval" action and an "Approval" card would extend, in the same visual language, not a new page pattern.
- **Roles & Permissions (Sprint 22B) already uses a `{module}.{action}` convention** (`sales_order.view/create/update/delete`, `purchase_order.*`, `journal_entry.*`) enforced via Spatie's `permission:` middleware, with `RoleService::syncPermissions()` already the single write path for what a role can do. A fifth action, `.approve`, is a direct, mechanical extension of a pattern that already exists — not a new authorization concept.
- **The Audit Log (Sprint 22B) already has exactly the write path this design needs**: `AuditLogService::record(action, module, description, properties, userId)`, already called from `AuthService`, `UserService`, and `RoleService` for exactly this kind of event (created/updated/activated/permissions_updated). Approval requests/approvals/rejections are additional callers of the identical method — not a new logging mechanism.
- **The Dashboard's Pending Tasks widget (Sprint 23B) already exists and already lists draft counts per module** (`purchase_order`, `sales_order`, `journal_entry`), each row clickable through to that module's list page — confirmed live. This is the direct, already-built extension point for a "Pending My Approval" row, described in §4.
- **No `ApprovalController`/`ApprovalService` exists anywhere** — confirmed by directory listing. This design's job is to specify what those should be, not to have found them half-built.

---

## 1. Approval Architecture

**Core decision: approval is an optional, per-document-type gate in front of the one existing `submit()` method — never a parallel status machine, never a document-type-specific reimplementation.**

```
                     Documentable::submit()  (unchanged entry point,
                            │                 every document type calls it)
                            ▼
              ┌─────────────────────────┐
              │ requiresApproval()?      │  new: defaults to false: every
              │ (per document type,      │  currently-shipped type (Invoice,
              │  §3 — opt-in only)       │  Delivery, Credit/Debit Note, Goods
              └──────────┬───────────────┘  Receipt, Stock Adjustment, Payment/
                         │                   Receipt Entry) is untouched.
              ┌──────────┴───────────┐
              ▼ no                    ▼ yes (Sales Order, Purchase Order,
      existing behavior,               manual Journal Entry — §3)
      submit() proceeds        ┌──────────────────────────┐
      exactly as it does       │ latest ApprovalFlow row   │
      today                    │ for this document         │
                                └──────┬────────────────────┘
                     ┌──────────────────┼──────────────────┐
                     ▼ none yet          ▼ PENDING           ▼ APPROVED
             submit() blocked:    submit() blocked:    submit() proceeds
             "Request Approval    "Awaiting approval"   exactly as it does
             first" (§2)                                today — unchanged
                                         ▲
                                  ▼ REJECTED
                          submit() blocked: "Rejected —
                          revise and request again" (§2)
```

`ApprovalFlow` itself needs **zero schema change** — every field this design uses (`approvable_type`/`approvable_id`, `approver_id`, `status`, `step`, `remarks`, `decided_at`) already exists. The only new backend surface is:
- One new method on `Documentable` (or a small trait it composes), `requiresApproval(): bool`, defaulting to `false`, overridden per opted-in model (§3) — the same override-point shape `afterCreate()`/`afterSubmit()`/`afterCancel()` already establish.
- One new `ApprovalService` (mirrors `TaxService`/`AuditLogService`'s own shape: one service, one write path, injected wherever needed) with `requestApproval()`, `approve()`, `reject()` — each a thin wrapper that creates/updates an `ApprovalFlow` row, calls `AuditLogService::record()` (§5), and (for `approve()`) nothing else — it does **not** call `submit()` itself; approving unblocks submission, it doesn't perform it, keeping "was this approved" and "was this submitted" as two independently-answerable, auditable facts.
- One new `ApprovalController` (read: list pending-for-me; write: request/approve/reject) — a new, small resource, not a modification of `SalesOrderController`/`PurchaseOrderController`/`JournalEntryController`.

This mirrors `TAX_ENGINE_DESIGN.md §1`'s own core decision almost exactly — a single reusable engine every consumer calls into, with zero change to how those consumers already work when they don't opt in.

---

## 2. Workflow States

Four states, exactly as specified. Only three are ever *stored* (as `ApprovalFlow.status`, already an `ApprovalStatus` enum with those exact three cases) — **Draft is not an approval state at all, it's the absence of one.**

| State | Where it lives | Meaning |
|---|---|---|
| **Draft** | `document.status = DRAFT` (existing, unchanged) | No `ApprovalFlow` row exists yet, or none is currently pending — the document is still freely editable, exactly as today. |
| **Pending Approval** | Latest `ApprovalFlow` row for this document has `status = PENDING` | `requestApproval()` was called: an `ApprovalFlow` row is created (`step = 1`, `approver_id` = the resolved approver, `status = PENDING`). The document's own `status` column **stays `DRAFT`** — it is not editable-vs-locked at the `DocumentStatus` level; `submit()` simply refuses to proceed while a `PENDING` row is the latest one (a small, additive guard in the document's own edit path, mirroring how `submit()` already guards against a non-draft document). |
| **Approved** | Latest `ApprovalFlow` row has `status = APPROVED`, `decided_at` set | `approve()` was called by someone holding the `.approve` permission (§3). `submit()`'s new guard now passes — the document can be submitted exactly as before. Approval does not auto-submit; a human still clicks Submit, the same explicit action every document already requires today. |
| **Rejected** | Latest `ApprovalFlow` row has `status = REJECTED`, `remarks` holds the reason | `reject()` was called. Document stays `DRAFT`, fully editable. `requestApproval()` can be called again after edits — creating a **new** `ApprovalFlow` row (`step` increments), preserving the rejected row as history rather than overwriting it. "Latest row wins" is the only rule the UI/backend ever needs — no need to delete or mutate a decided row. |

**v1 approver resolution — kept simple, per the ticket's own instruction:** one approver per request, resolved at `requestApproval()` time as *any user holding the relevant `.approve` permission* (not a named individual, not a hierarchy) — the first such user to act decides it. `ApprovalFlow.step` already exists specifically so a v2 can insert `step = 2, 3, ...` rows for sequential multi-approver chains (§6) without a schema change; v1 simply never creates a second step.

---

## 3. Supported Documents

| Document | Requires approval? | Why |
|---|---|---|
| **Sales Order** | **Yes** | An operational commitment to a customer — quantities, pricing, delivery terms — made before any inventory or receivable exists yet. A manager sign-off before it becomes visible to Delivery/Invoicing is a real, common control point, and `SalesOrderService::submit()` is already the single place this design's guard needs to sit. |
| **Purchase Order** | **Yes** | The operational mirror of Sales Order — a spending commitment to a supplier, made before any Goods Receipt or Accounts Payable exists. Same reasoning, same interception point (`PurchaseOrderService::submit()` → `Documentable::submit()`). |
| **Journal Entry — manual only** | **Yes, scoped** | See §0's grounding: a manual entry (`reference_type = null`, confirmed live as its own filterable category today) is a human directly writing to the General Ledger outside the normal document flow — exactly the kind of write a control point belongs on. |
| **Journal Entry — system-generated** (posted by Invoice/Credit Note/Debit Note/Payment/Receipt Entry submission) | **No** | These are not independent documents a person drafts and submits — they're the *atomic accounting consequence* of submitting an already-approved-if-applicable source document (an Invoice's own `journalLines()` posts inside the same transaction as the Invoice's own submit). Gating them behind a second, separate approval would split one atomic operation into two, leaving the source document "submitted" while its GL impact sits pending — the exact kind of inconsistency the Accounting Engine's transactional design has never allowed. `requiresApproval()` for `JournalEntry` returns `true` only `when $this->reference_type === null`. |

**Explicitly not evaluated for approval in this design** (per the ticket's own three named documents): Invoice, Delivery, Credit Note, Debit Note, Goods Receipt, Stock Adjustment, Payment Entry, Receipt Entry. `requiresApproval()` defaults to `false` for every one of them — zero behavior change. Any of these could opt in later by overriding one method; nothing here forecloses that.

---

## 4. Permission Strategy

**Reused, not reinvented — the identical `{module}.{action}` convention Sprint 22B already shipped, extended with one new action.** No approval-specific role, no hardcoded "Manager" concept, no role-name branching anywhere in this design.

| Action | Permission | Enforced where |
|---|---|---|
| Request approval | `sales_order.update` / `purchase_order.update` / `journal_entry.update` (the document's own existing update permission — requesting approval is an action *on* the document, not a new capability) | Existing `permission:` middleware on the document's own routes — no new permission needed here |
| Approve / Reject | `sales_order.approve` / `purchase_order.approve` / `journal_entry.approve` (three new permission rows, same seeding mechanism `RolePermissionSeeder` already uses for view/create/update/delete) | New `ApprovalController` routes, gated exactly like Sprint 22B gated Company/Roles/Users/Audit Log — `->middleware('permission:{module}.approve')` |
| View pending approvals (§4 dashboard, §1 controller's read side) | Same `.approve` permission — if you can't decide it, you don't need to see it queued | New `ApprovalController@pending` route |

**Why this avoids "role-specific logic," per the ticket's own instruction:** nothing in `ApprovalService`/`ApprovalController` ever checks a role name. `Route::middleware('permission:sales_order.approve')` is the entire authorization surface — exactly how every other gated route in this codebase already works since Sprint 22B. Whether "Owner" or "Accountant" ends up holding `.approve` for a given module is a Roles & Permissions configuration decision (already fully editable via the shipped matrix UI), never a decision this design bakes in.

---

## 5. Audit Integration

**Every approval-lifecycle event is one more caller of `AuditLogService::record()` — no second logging mechanism, per the ticket's explicit instruction.**

| Event | `action` | `module` | `description` example |
|---|---|---|---|
| Request submitted | `approval_requested` | the document's own module (`sales_order`, `purchase_order`, `journal_entry`) | `"Requested approval for SO-00004."` |
| Approved | `approval_approved` | same | `"Approved SO-00004."` |
| Rejected | `approval_rejected` | same | `"Rejected SO-00004: {remarks}."` |

This is the identical shape `RoleService::syncPermissions()` already uses (`$this->auditLogService->record('permissions_updated', 'role', "...", ['permissions' => $permissionNames])`) — `ApprovalService` calls the same method, with the approval-specific `properties` payload (e.g. `['approval_flow_id' => ..., 'step' => ...]`) carried in the existing `properties` JSON column, not a new column. The Audit Log page (Sprint 22B, already filterable by module/date/search) needs **zero changes** to display these — they're just new rows with new `action`/`module` values, exactly as designed for extensibility there.

**Note on `DocumentTimeline` vs `AuditLog` — kept exactly as separate as Sprint 22B established them.** `DocumentTimeline` (per-document activity feed, already shows "created"/"submitted"/"cancelled" on a document's own timeline) also gets an entry per approval event, via the identical mechanism `Documentable::submit()` already uses (`app(DocumentTimelineService::class)->record($this, 'approval_requested')`, etc.) — this is *not* a duplicate logging mechanism, it's the same "one document-scoped feed, one system-wide feed, both fed by the same real event" split Sprint 22B's own design already drew and this design simply continues.

---

## 6. Dashboard Integration

**Reuses `DashboardService::pendingTasks()` (Sprint 23B) — one more row in the same list, not a new widget class or a new aggregation mechanism.**

`pendingTasks()` today returns `[{module, label, count}]` for draft Purchase Orders, draft Sales Orders, and unposted Journal Entries — each `count` a `->count()` call the widget already renders as a clickable row via `PendingTasksCard`. This design adds exactly one more row, computed the same way:

```php
// Illustrative only — not implemented in this sprint.
['module' => 'approval', 'label' => 'Pending My Approval', 'count' => $this->approvalFlowRepository->countPendingFor(Auth::id())]
```

- **Gating**: the existing `PendingTasksCard` has no permission gate today (confirmed Sprint 23B — it's always visible when authenticated, same as Recent Transactions). "Pending My Approval" should follow the same rule: visible to anyone, showing `0` for a user holding no `.approve` permission anywhere (the count is inherently already scoped to *that user's* actionable approvals via `approver_id`/permission, so an empty result is the correct, self-limiting answer — no widget-level permission gate needed beyond what the query itself already enforces).
- **Drill-down**: the existing `MODULE_LINKS` map in `PendingTasksCard` (module → list page path) gains one more entry, `approval → /approvals` (a new, small list page — out of scope to design in detail here since the ticket's own dashboard section only asks how it *appears*, not a new page's full design).
- **No new dashboard aggregation logic**: `DashboardService::pendingTasks()` already does nothing but forward per-module counts from each module's own repository — this design's addition is one more repository call in that same array, following the identical shape every other row already has.

---

## 7. Future Expansion Strategy

- **Multi-level approval** — already has its schema seed: `ApprovalFlow.step` (an integer, already on the table, already cast) exists specifically for this. v1 only ever creates `step = 1`. A future sprint adds `step = 2, 3, ...` rows created only after the prior step's row reaches `APPROVED`, and `requiresApproval()`'s per-type config gains an optional "how many steps" number. `ApprovalService::approve()` already operates on "the latest row for this document" — multi-step only changes what "latest" means (latest *undecided* step, not latest overall), not the interception point in `Documentable::submit()`.
- **Approval delegation** — `ApprovalFlow.approver_id` is already a plain foreign key to `User`, not a computed value. Delegation ("Alice's pending approvals also go to Bob while she's out") is a lookup this design's approver-resolution step (§2 — "any user holding `.approve`") can consult before assigning `approver_id`, without changing anything about how a decided row is recorded or how `submit()` reads it.
- **Notification Center** — `ApprovalService::requestApproval()`/`approve()`/`reject()` are already the exact three moments a notification would fire from (per §5, they already call `AuditLogService::record()` at these moments) — a future Notification service becomes one more call alongside the existing audit-log call at each of those three points, the same "additive caller, not a new event system" shape §5 already uses. This is also the identical extension point `DASHBOARD_DESIGN.md §7` already named for a future Notifications widget — the two designs converge on the same seam.

All three extend *within* the shape this design already establishes — a `step`-aware `ApprovalFlow` row, a resolvable `approver_id`, and a small fixed set of call sites (`requestApproval`/`approve`/`reject`) — none require touching `Documentable::submit()`'s own interception point again.

---

## 8. Browser Verification Report

Performed against the running app (`php artisan serve` + `vite dev`, admin session) before writing this design, per the ticket's mandatory inspection step:

- **Sales Orders, live**: confirmed 3 orders, all already `Submitted` — no live example of a Draft Sales Order existed at inspection time, so the "Request Approval" slot was verified structurally (via `SalesOrderService::submit()`'s source) rather than visually on this document type.
- **Purchase Orders, live**: confirmed a real Draft document, `PO-00001`, and inspected its detail page directly — action bar reads `Edit / Submit / Delete`, with an "Audit Information" card (Created/Submitted/Cancelled). This is the concrete, live UI slot §1/§2 extend — a future "Request Approval" action replacing/gating the existing "Submit" button, and a new "Approval" card in the same visual position as "Audit Information."
- **Journal Entries, live**: confirmed 10 entries; critically, confirmed **live** (not just in code) that `Reference Type: Manual` is already a real, distinct, filterable value (`JE-00007`, `JE-00008` — the latter still sitting in `Draft` status right now) versus `Invoice`/`Credit Note`/`Debit Note` for system-generated entries — directly grounding §3's scoping rule in observed data, not an assumption.
- **Dashboard, live**: confirmed the Pending Tasks widget already renders `Draft Purchase Orders` / `Draft Sales Orders` / `Unposted Journal Entries` as clickable rows today — the exact, unmodified extension point §6 adds one row to.
- **Administration, live**: confirmed Roles & Permissions still fully functional (no regression from prior sprints) — the exact matrix UI a future `.approve` permission would appear in as one more column, with zero changes to that UI itself.
- No console errors observed during this inspection; no write actions were attempted (design-only sprint).

**Post-design fit check**: the proposed `ApprovalController`/`ApprovalService` and the one new dashboard row were checked against the live app's existing conventions — new routes would sit in `routes/api.php` gated by `permission:` middleware exactly like every route Sprint 22B added, and the one new Pending Tasks row renders through a component (`PendingTasksCard`) already confirmed live and already shaped to accept exactly this kind of `{module, label, count}` entry with zero layout change.

---

## Open Questions

1. **Where `requiresApproval()` per-type configuration actually lives** — a hardcoded `true`/`false` override per model (simplest, matches how `afterSubmit()` etc. already work) vs. an Administration-configurable toggle (more flexible, but a new settings surface this design didn't ground). Leaning toward the hardcoded override for v1 — flagged for reviewer input, the same "flagged, not solved" discipline this series already uses.
2. **What happens to an `ApprovalFlow`'s `PENDING` row if the document is edited while awaiting approval** — should editing silently invalidate the pending request (forcing a fresh `requestApproval()`), or should edits be blocked entirely while `PENDING`? This design leans toward "editing is blocked while a `PENDING` row exists" (matches how `submit()` already blocks a non-Draft document from being edited) but this needs explicit reviewer confirmation before implementation.
3. **The new `/approvals` list page** (§6's drill-down target) is named but not designed here — the ticket's own Dashboard Integration section asked only how Pending Approval *appears* on the existing Dashboard, not for a full new page design. A follow-up design sprint would cover it the way `ADMINISTRATION_DESIGN.md` covered Users/Roles/Audit Log as individual pages.

Stopping here — no code, no migrations, no tests, no frontend pages. Waiting for architectural review.
