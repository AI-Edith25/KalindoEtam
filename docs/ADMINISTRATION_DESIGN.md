# Administration Module — Design Document

Status: **Design only — not implemented.** Sprint scope: produce this document, get it reviewed. No code, no migrations, no tests, no frontend pages until approved.

Frozen inputs this design must not modify: **Accounting Engine, Tax Engine, Invoice, Credit Note, Debit Note, Accounts Receivable, Journal Entries, General Ledger, Trial Balance, Profit & Loss, Balance Sheet, Cash Flow, Period Closing, Master Data (Items/Suppliers/Customers/Warehouses/Item Groups/UOMs/Chart of Accounts/Taxes)**. This design proposes a **new, additive top-level module** — one new `companies` frontend page over the already-existing `Company` CRUD, one new `role_id`-free permission-matrix UI over the already-existing Role/Permission CRUD, one new read-only Audit Log page, and (flagged explicitly, not built) a genuinely new User Management backend surface that does not exist today. Nothing in this document is migrated or implemented.

## 0. What already exists (grounding) — the central discovery of this sprint

Confirmed by inspecting the running app (Chrome — sidebar navigation, user menu, Master Data, Settings link) and reading `User`/`Role`/`Permission`/`Company`/`Branch`/`DocumentTimeline`/`DocumentAttachment` models, their controllers, services, and `routes/api.php` in full before writing anything below:

- **"Settings" is a dead link today.** It's a real sidebar item (`navigation.ts`) pointing at `/settings`, but no `/settings` route is registered in `router.tsx` — the app's own catch-all route (`path="*"` → `Navigate to="/dashboard"`) silently swallows it. Confirmed live: clicking Settings flashes `/settings` in the URL bar and immediately bounces to `/dashboard`. The code comment on `navigation.ts` says exactly this: *"Settings falls through the router's catch-all back to /dashboard until its module is built."* This sprint is that module being designed.
- **The topbar/sidebar company name is hardcoded, not data-driven.** `Sidebar.tsx` and `Header.tsx` both literally contain the string `"PT Kalindo Etam"`. The actual `Company` row in the database is named `"CN Demo Co"`. These have never been connected. This is the single most concrete illustration of why a real Company page matters — today changing the organization's name requires editing source code, not filling a form.
- **`Company` already has a complete backend** — `CompanyController` (`index/store/show/update/destroy`), `CompanyService`, full validation (`StoreCompanyRequest`/`UpdateCompanyRequest`), and `Route::apiResource('companies', ...)` already registered. The model's current fields are `{name, code, address, phone, email, currency, timezone, fiscal_year_start}` — **no `logo`, no NPWP field exists yet**. No frontend page exists (`/companies` unmapped in `router.tsx`), matching the exact "backend ships ahead of any UI" pattern `TAX_ENGINE_DESIGN.md §0` already found for Tax and Currency. One `Company` row and one `Branch` row (`is_head_office`) exist today; `Branch belongsTo Company` is already a real foreign key, not a future concept.
- **User Management has *no backend at all* beyond authentication.** `routes/api.php` has exactly three user-related endpoints: `POST auth/login`, `POST auth/logout`, `GET auth/me`. There is no `UserController`, no `Route::apiResource('users', ...)`, no create/edit/deactivate/reset-password endpoint anywhere in the codebase. Confirmed by grepping the full `User` model, the full route file, and the full `Http/Controllers/Api/V1` directory — none exist. Today there is exactly **one** user in the database (`admin@example.com`). This is a materially different starting point than every other module this design series has touched: Tax, Currency, Role/Permission all had a *dormant but complete* backend waiting for a UI; User Management has no backend to activate.
- **Role & Permission has a complete, working backend that is never enforced.** `RoleController` (full CRUD) and `PermissionController` (list-only — permissions are seeded, not created) are both fully implemented and routed, including `POST roles/{role}/permissions` which does a full `syncPermissions()` replace. `RolePermissionSeeder` seeds exactly **30 modules × 4 actions** (`view/create/update/delete`) = **120 permissions**, named `{module}.{action}` (e.g. `tax.view`, `invoice.create`). Today there is exactly **one role** (`Admin`, holding all 120 permissions) and **zero route middleware** anywhere (`permission:`, `role:`, `can:` — grepped the full route file, none found). Spatie's permission model is fully wired end-to-end at the data layer and completely decorative at the enforcement layer. No frontend page exists for this despite the backend being the most complete of any module in this design.
- **There is no system-wide Audit Log.** Two related-but-different things exist, and conflating them would be a mistake:
  1. **`HasAuditTrail` + `AuditableObserver`** — a trait used by nearly every model in the app (`User`, `Role`, `Permission`, `Company`, `Tax`, `Invoice`, …) that silently stamps `created_by`/`updated_by`/`deleted_by` on every write. This is **field-level attribution**, not an event log — it answers "who last touched this row," never "what happened and when," and it is never queried or displayed anywhere in the frontend today.
  2. **`DocumentTimeline`** — a real, working activity-log table (`subject_type`/`subject_id` polymorphic, `action`, `description`, `properties` JSON, `created_by`, timestamps), with `DocumentTimelineService::record()`/`forSubject()` and a live endpoint (`GET document-timeline?subject_type=&subject_id=`). This is genuine, reusable audit infrastructure — but it is **scoped to one document at a time** (its only query method is "give me the timeline for this one subject"), has **no IP address column**, has **no explicit "module" column** (`subject_type` — e.g. `App\Models\Invoice` — plays that role loosely), and has **no list-all/search/date-range endpoint**. A global, filterable Audit Log page as the ticket describes cannot be built against `DocumentTimeline` as it stands today without a new aggregating read endpoint — flagged honestly in §6, not glossed over.
- **`DocumentAttachment` is a complete, reusable polymorphic file-upload system** — `attachable_type`/`attachable_id` morph, `disk`/`file_path`/`mime_type`/`file_size`, fully routed (`GET/POST attachments`, `DELETE attachments/{id}`). This is the natural, zero-new-infrastructure answer to "Company Logo" (§3) — the same "activation of dormant infrastructure" pattern as everything else in this grounding section.
- **Master Data's own pattern is the direct template for Administration's internal layout**: a `SectionNav` of tabs (`Items | Suppliers | … | Taxes`) each rendering `PageHeader` + `ActionBar` + `DataTable`/form, exactly matching what Sprint 21B built for Taxes. Administration reuses this identical shape — see §1/§7.
- **Shared components confirmed available for reuse, exact names**: `PageHeader`, `ActionBar`, `DataTable`, `FilterPanel` (the ticket's "FilterBar" — this codebase's actual component is named `FilterPanel`), `SearchBox`, `StatusBadge`, `DetailDrawerLayout`, `RowActionsMenu`, `ConfirmationDialog`/`DeleteDialog`, `Pagination`, `SectionNav`. No new shared component is proposed by this design.

---

## 1. Administration Architecture

**Core decision: Administration is a new top-level nav section, structured identically to Master Data — one `SectionNav` with four tabs, each tab a self-contained page reusing the exact list/form/drawer patterns already proven by Tax, Role, and Company's siblings.** No new architectural pattern is introduced anywhere in this design; every page below is a recombination of components and API endpoints that already exist (with User Management's backend gap called out explicitly rather than assumed away).

```
Sidebar: "Administration" (replaces "Settings", same position)
                │
                ▼
        /administration/company   ← default landing tab (redirect, same
                │                    convention /master → /master/items uses)
   ┌────────────┼────────────────┬──────────────────┐
   ▼            ▼                ▼                  ▼
Company       Users        Roles & Permissions   Audit Log
(edit-in-   (DataTable +  (DataTable of Roles  (read-only
 place       drawers,      + a permission-      DataTable,
 single       reusing       matrix editor)       filters,
 record,      Master                             no writes)
 §3)          Data's own
              list/drawer
              shape, §4 —
              backend gap
              flagged)
```

Four sections, exactly as specified — no fifth section proposed. Two candidate additions were considered and rejected for the same reason the ticket already anticipates:
- **"General"/branding settings** (app name, favicon, theme) — no backend concept for this exists anywhere, and nothing in the ticket's own admin-flow inspection surfaced a real need; this is exactly the "oversized legacy Settings menu" scope creep the ticket warns against. Not included.
- **"Notifications"** — no notification infrastructure (email templates, in-app alerts) exists in this codebase at all. Not included, not even as a future placeholder, because there is no concrete driver for it yet (contrast with Multi-Company/SSO/2FA in §7, which the ticket itself names as real future direction).

---

## 2. Navigation Structure

```ts
// frontend/src/layouts/navigation.ts — proposed change, not implemented
{ label: 'Administration', path: '/administration/company', icon: Settings }
// replaces: { label: 'Settings', path: '/settings', icon: Settings }
```

Route table (mirrors `/master/*`'s own flat structure exactly):

| Path | Page |
|---|---|
| `/administration/company` | Company (single-record edit page, §3) |
| `/administration/users` | User list (§4) |
| `/administration/roles` | Role & Permission list + matrix editor (§5) |
| `/administration/audit-log` | Audit Log (read-only, §6) |

**Why "Administration" fits better than "Settings":**
- "Settings" is an unbounded word — in every legacy ERP (and in this very ticket's own explicit exclusion list: Currency, Backup, Import/Export, Marketplace, Developer Tools) it becomes the dumping ground for anything that doesn't obviously belong elsewhere, because nothing about the word "Settings" scopes what belongs inside it. "Administration" scopes by *audience and intent*: it names who this module is for (a system administrator) and what kind of decisions live there (organizational identity, who can log in, what they can do, what they did) — not "how the app behaves."
- It matches this codebase's own established naming convention: every top-level nav item names the **domain** it governs — "Master Data" governs reference data, "Accounting Reports" governs reporting, "Finance" governs payments. "Administration" governs the organizational/security domain the same way, rather than being a generic catch-all word sitting alongside domain names.
- It is literally the section name the ticket itself specifies end-to-end — nav label, doc section headers, and route prefix (`/administration/*`) all agree, so there's no translation layer between "what admins click" and "what the codebase calls it," the same 1:1 naming discipline `/master/taxes` already has for the Taxes tab.

---

## 3. Company Page Design

**Not a list page — a single-record edit page.** There is exactly one `Company` row today and `Branch` (already `belongsTo Company`) is the existing model for anything that needs to be plural (§7 — Multi-Company). Building Company as a `DataTable` of one row would be the wrong shape; this follows the same "one settings object, not a list" reasoning as Period Closing's Fiscal Year concept, just simpler (no create/delete — only ever one Company record is edited in place, matching what `CompanyController` already supports today via `show`/`update` on the single existing row).

**Layout**: `PageHeader` (title "Company", no count badge — singular by design) → a single form section (same `Input`/`FormField` building blocks `TaxFormDrawer` already uses, rendered inline on the page instead of in a `Sheet`, since there's no list to slide out from) → one `ActionBar` action: **Save Changes**.

| Field | Status | Why it belongs here |
|---|---|---|
| **Company Name** | Existing (`name`) | The organization's identity of record. Directly fixes §0's hardcoded-string finding — this is the field that should be driving `Sidebar.tsx`/`Header.tsx` instead of a literal string (wiring that up is implementation, not this sprint, but it's the entire reason this field is here). |
| **Company Logo** | New | Brand identity on printed documents (`InvoicePrintPage` already exists and would be the natural next consumer) and the topbar. No `logo` column exists on `Company` today. **Recommendation**: reuse `DocumentAttachment` (§0) with `Company` as the polymorphic `attachable` — zero schema change, the same upload/list/delete endpoints already used elsewhere. Flagged as an open question (§ Open Questions) whether "logo" should instead be a dedicated single `logo_path` column, since `DocumentAttachment` is naturally *many* attachments and a logo is conceptually *one* — a reviewer call, not resolved here. |
| **Address** | Existing (`address`) | Already a `Company` field; appears on printed Invoices/Purchase Orders. Currently has no UI to set it at all — it can only be null or set via direct DB/tinker access today. |
| **Phone** | Existing (`phone`) | Same reasoning as Address — contact info for printed/compliance correspondence, existing column, no UI. |
| **Email** | Existing (`email`) | Same reasoning — existing column, no UI. |
| **NPWP** | New | Indonesia's company tax-identification number (Nomor Pokok Wajib Pajak) — legally required on a real tax invoice (Faktur Pajak). `TAX_ENGINE_DESIGN.md §0/§9` already established this codebase's Indonesian VAT/PPN context and explicitly flagged "Company has no tax-registration field" as a known gap and a natural future need. This field is that gap's direct answer. No column exists yet — proposed as a new nullable string on `Company`, not built now. |
| **Fiscal Year** | Existing (`fiscal_year_start`) | Already drives the real, already-built Fiscal Year/Accounting Period system (`FiscalYear`, `AccountingPeriod`, Period Closing). Today it can only be read/set via the same no-UI path as Address/Phone/Email. This page is simply where an admin finally sees and edits the organization's own fiscal calendar anchor, rather than it being invisible. |

**Deliberately excluded** (per the ticket, with the reasoning made explicit rather than just listed):
- **Currency** — already correctly lives in Master Data (its own CRUD, `currencies` endpoint, same shape as Tax) — a reference/picklist entity shared across documents, not an organizational-identity field. Moving it here would duplicate Master Data's own domain boundary.
- **Tax Management** — already shipped as Master Data → Taxes (Sprint 21B). Not duplicated here.
- **Backup, Import/Export, Marketplace, Developer Tools** — no backend infrastructure for any of these exists anywhere in this codebase (no backup service, no marketplace concept, no plugin/API-key system). These aren't deferred with a plan — they're omitted because there's no concrete driver, the same standard `TAX_ENGINE_DESIGN.md §3` used to explicitly exclude Withholding Tax as a `TaxType` case.

---

## 4. Users Page Design

**The one section where "reuse the existing architecture" runs into a real gap: there is no user-management backend to reuse (§0).** This section designs the *UI* per the ticket's structure, and separately, honestly states what backend work is a prerequisite — without designing that backend, per the ticket's own "design only" scope for *this* sprint being Administration's UI/architecture, not an API spec.

**Layout**: `PageHeader` ("Users") + `ActionBar` (Refresh, **New User** primary action) + `SearchBox` + `DataTable` (**Name | Email | Role | Status | Actions**) + `Pagination` — the identical shape `TaxListPage`/`CustomerListPage`/every Master Data list already uses. Row actions via `RowActionsMenu`: View, Edit, Activate/Deactivate, Reset Password, Assign Role — a `TaxFormDrawer`-shaped `Sheet` for Create/Edit, a `StatusBadge` for Active/Inactive (`User` already has `SoftDeletes` from `HasAuditTrail`, which could plausibly back "deactivate," see Open Questions).

| Requirement | Design | Reuse note |
|---|---|---|
| **User List** | `DataTable`, columns above | Same `useEntityListPage` hook `TaxListPage` already uses |
| **Create User** | `Sheet` form: Name, Email, Password (or "send invite" — reviewer call), Role select | `User` already has `name`/`email`/`password` fillable + `HasApiTokens`/`Notifiable` from Sanctum — the auth substrate is real, only the CRUD surface is missing |
| **Edit User** | Same `Sheet`, prefilled | — |
| **Activate / Deactivate** | `StatusBadge` toggle, same pattern as Tax's Activate/Deactivate (§ Tax design, already shipped) | `User` has `SoftDeletes` already — "deactivate" could mean a new `is_active` boolean (explicit, reversible, doesn't touch auth) rather than soft-delete (which has delete semantics elsewhere in this app) — **recommended**: new boolean, not reusing `deleted_at`, to avoid a deactivated user's rows disappearing from `creator()`/`updater()` audit-trail relations elsewhere in the app |
| **Reset Password** | Row action → confirmation dialog → backend generates/emails a temporary credential | **No backend exists for this at all** — `AuthController` has no forgot/reset endpoint. This is new backend surface, not a reuse of anything. Flagged, not designed in detail here (out of scope for "reuse existing architecture" since there's nothing to reuse) |
| **Assign Role** | Single-select dropdown sourced from `GET roles` (already exists) | `User` already `use`s Spatie's `HasRoles` trait — assigning is `$user->syncRoles([...])`, the identical shape `RoleController::assignPermissions` already uses for permissions. This part **is** a real reuse — only the User CRUD wrapper around it is new. |

**Prerequisite backend work this design surfaces (not built now)**: a `UserController` (`index/store/show/update/destroy`), a `role_id`/role-name field on create, an `is_active` column + toggle endpoint, and a password-reset flow. This mirrors exactly how `TAX_ENGINE_DESIGN.md` flagged "Purchase has zero tax handling" as a known, named gap rather than silently assuming it — Users' backend gap is this design's equivalent finding.

---

## 5. Roles & Permissions Design

**Recommendation: reuse the existing architecture as-is — do not redesign the permission model.** The backend (§0) is already the most complete of any module in this design: full Role CRUD, a working `syncPermissions` replace-all endpoint, and a clean `{module}.{action}` naming convention across exactly 30 modules × 4 actions. The only genuinely missing piece is the UI.

**Layout — two views under one tab**:
1. **Role list**: `PageHeader` ("Roles & Permissions") + `ActionBar` (**New Role**) + `DataTable` (**Role Name | Permission Count | Actions**) — same shape as every other list page.
2. **Permission matrix editor** (opened per-role, via row action "Edit Permissions" or from the New Role flow): not a `Sheet` form field-by-field like Tax/Company, but a **grid**: rows = the 30 modules (grouped, not hardcoded — see below), columns = **View / Create / Update / Delete**, each cell a checkbox. A "Select all" per row (module) and per column (action) for speed. Submitting sends the complete resulting permission-name array to the existing `POST roles/{role}/permissions` endpoint in one call — that endpoint already does a full `syncPermissions()` replace, which is exactly what a checkbox-grid submit naturally produces (no partial-update endpoint needed, no backend change).

**Grouping is a frontend concern, not a backend redesign**: `Permission.name` is a single string (`"tax.view"`), with no separate `module`/`action` columns in the database. The matrix UI groups permissions by splitting each name on `.` client-side (`tax.view` → module `tax`, action `view`) purely in the React layer — the same "don't touch the model, read it differently" discipline `TAX_ENGINE_DESIGN.md §1` used for keeping `TaxService` a pure calculator. No backend or schema change proposed.

**Evaluating whether to reuse or refine, as the ticket asks**: reuse, unmodified. The one real weakness found — permission checks exist nowhere in `routes/api.php` (§0) — is an **enforcement** gap, not a **modeling** gap, and enforcement is an orthogonal, larger effort (deciding whether to gate at route middleware, controller, or Form-Request level, across every existing endpoint) that doesn't belong in this Administration-module design and isn't required to make the Roles & Permissions *UI* useful — an admin can already correctly build and assign role/permission sets today even though nothing currently reads them at request time. Flagged explicitly as a known limitation (§7/Open Questions), not silently fixed here.

---

## 6. Audit Log Design

**Honest framing first**: the ticket asks for User / Action / Module / Timestamp / IP Address, with Search / Filter / Date Range, "reusing existing audit infrastructure where possible." §0 already established that no single existing thing provides this. The closest real infrastructure is `DocumentTimeline`, and it only gets partway there.

| Ticket field | Mapped from | Gap |
|---|---|---|
| User | `DocumentTimeline.created_by` → `creator` relation (from `HasAuditTrail`) | None — already present |
| Action | `DocumentTimeline.action` | None — already present |
| Module | `DocumentTimeline.subject_type` (e.g. `App\Models\Invoice`) | Present but coarse — it's a PHP class name, not a human module label; frontend would need a small `subject_type → display label` map (e.g. Tax, Invoice, Role), the same kind of lookup table the Roles matrix already needs for module names (§5) |
| Timestamp | `DocumentTimeline.created_at` | None — already present |
| IP Address | — | **Does not exist anywhere.** No request-IP capture happens on any write path today (`AuditableObserver` only stamps `created_by`, not `request()->ip()`). Genuinely new backend work: either add an `ip_address` column to `document_timelines` and populate it in `AuditableObserver`/`DocumentTimelineService::record()`, or accept the Audit Log ships without IP initially. Not designed further here — flagged as the one field with zero existing foundation. |
| Search / Filter / Date Range | — | **No list-all endpoint exists.** `GET document-timeline` today requires `subject_type` + `subject_id` (one document at a time) — there is no cross-subject query. A real Audit Log page needs a new endpoint, e.g. `GET document-timeline` (no required subject) with `user_id`/`module`/`action`/`date_from`/`date_to` filters — an additive, backward-compatible change to `DocumentTimelineController::index` (the existing per-subject call is a special case of the same query), not a redesign. |

**Layout** (assuming the above backend gap is closed in a future sprint, not this one): `PageHeader` ("Audit Log") + `FilterPanel` (User select, Module select, Date Range, free-text search on `description`) + `DataTable` (**User | Action | Module | Timestamp | IP Address**, IP column blank/omitted if that field ships later than the rest) + `Pagination`. Entirely read-only — no `ActionBar` primary action, no row actions, matching the ticket's own framing of this as an observability page, not a workflow.

**Reused, not reinvented**: the underlying event-capture mechanism (`DocumentTimelineService::record()`, called from wherever documents already log actions like "submitted"/"cancelled") is kept exactly as-is — this design only proposes *reading* it more broadly (new list endpoint) and *capturing one more field* (IP), never a new logging table or a parallel audit system alongside it.

---

## 7. Future Expansion Strategy

- **Multi Company** — already has a real seed: `Branch belongsTo Company` is a genuine foreign key today, not a placeholder (§0 — one Company, one Branch already exist). The Company page (§3) becomes a `DataTable` list (reusing the exact list/drawer shape already proposed for Users/Roles) the moment a second `Company` row is needed, with the existing `CompanyController` `index/store` endpoints requiring zero change — they already return/accept a collection, `show`/`update` already operate per-ID. Only the frontend shape (single-record page → list page) changes, and that's an additive UI change, not a backend one.
- **SSO** — `User` already uses Laravel Sanctum (`HasApiTokens`) for its token issuance, a scheme that already decouples "how a session is established" from "what a token can do." Adding an SSO provider means adding a new login path that, on success, issues the same Sanctum token `AuthService::login()` already issues today — the rest of the app (every `auth:sanctum` route, `AuthContext`, `meRequest()`) needs no change. The Users page (§4) would gain an "Auth Provider" column/filter (Local vs SSO) — additive, not a redesign.
- **Two-Factor Authentication** — same reasoning: 2FA is a gate *before* Sanctum token issuance (an extra step inside `AuthService::login()`), not a change to what a token grants afterward. The Users page would gain a "2FA Enabled" indicator and a per-user enforcement toggle — both additive columns/actions on the same `DataTable`/`Sheet` shape already designed in §4, not a new page or a new architecture.

All three extend the *edges* of what's designed here (Company becomes a list; Users gains columns and an alternate login path) without requiring any of §3–§6's core shapes — the list/matrix/read-only-log patterns — to be redone. This mirrors `TAX_ENGINE_DESIGN.md §10`'s own "Tax Groups" extension: additive to an existing method/shape, not a rebuild.

---

## 8. Browser Verification Report

Performed against the running app (`php artisan serve` + `vite dev`, admin session) before writing this design, per the ticket's mandatory inspection step:

- **Navigation, live**: confirmed 9 top-level items (Dashboard, Master Data, Inventory, Purchase, Sales, Finance, Accounting Reports, Reports, Settings). Clicking **Settings** navigates to `/settings` and is immediately redirected to `/dashboard` by the router's catch-all — a dead link, not a stub page, confirmed both in the URL bar and in `router.tsx`/`navigation.ts` source.
- **User menu, live**: the "Admin" button top-right opens a small menu showing name + email (`admin@example.com`) and a single **Log out** action — no Profile, no Change Password, no Settings entry point exists anywhere in the current UI.
- **Master Data, live**: reconfirmed (consistent with `TAX_ENGINE_DESIGN.md §0`) eight tabs — Items, Suppliers, Customers, Warehouses, Item Groups, UOMs, Chart of Accounts, Taxes. No Company, Users, or Roles tab exists there or anywhere else in the live app.
- **Company page**: does not exist (`/companies` unmapped) — could not be inspected live, consistent with the code-level finding. The visible proxy for "company identity" in the running app — the sidebar/header title "PT Kalindo Etam" — was confirmed to be a hardcoded string unrelated to the actual `Company` database row (`"CN Demo Co"`), grounding §3's Company Name field directly in an observed, live inconsistency rather than an assumption.
- **User Management / Roles & Permissions**: no live UI exists for either — confirmed by exhausting the sidebar and Master Data's own tabs; nothing under any existing section surfaces user or role administration today.
- **Audit-related functionality**: no live "Audit Log" or activity-feed UI found anywhere in the app during this inspection (dashboard, document detail pages, Master Data). This is consistent with §0's code-level finding that `DocumentTimeline`, while real on the backend, has no frontend consumer today.
- No console errors observed during this inspection; no write actions were attempted (design-only sprint).

**Post-design fit check**: with the proposed `Administration` nav item replacing `Settings` at the same sidebar position (§2), the sidebar item count stays at 9 — no structural change to the nav's shape, only the one dead entry becoming a real, functioning section. This was verified by comparing the live 9-item sidebar screenshot against the proposed `navigation.ts` diff in §2: a single-item label/path swap, nothing added or removed elsewhere in the list.

---

## Open Questions

1. **Company Logo storage** — reuse `DocumentAttachment` (many-attachments model, zero schema change) vs. a dedicated `logo_path` column on `Company` (single-value, arguably clearer intent). Leaning toward reusing `DocumentAttachment` per this design series' "minimal extension" doctrine, but not resolved — flagged for reviewer input, same as `TAX_ENGINE_DESIGN.md`'s own `tax_mode` placement question.
2. **User "deactivate" semantics** — a new `is_active` boolean (recommended, §4) vs. reusing the existing `deleted_at` soft-delete. Needs reviewer confirmation given `deleted_at` already has delete-shaped meaning elsewhere in this app (Tax, Invoice, etc.).
3. **Permission *enforcement*** — out of this design's scope (§5), but named explicitly as the real next step once Roles & Permissions has a UI: today, every `auth:sanctum` route is reachable by any authenticated user regardless of role. This design makes roles/permissions *administrable*; it does not make them *effective*. That's a separate, larger, cross-cutting design of its own.
4. **Reset Password delivery mechanism** (§4) — email-based reset link vs. admin-generated temporary password shown once. No email infrastructure was found anywhere in this codebase during grounding; if email-based, that's an additional prerequisite this design surfaces but does not solve.

Stopping here — no code, no migrations, no tests, no frontend pages. Waiting for architectural review.
