# Environment Validation — Sprint 8

Date: 2026-07-17
Scope: Validate the ERP MVP against the real Laragon MySQL environment and confirm frontend readiness. No new business modules were implemented.

---

## 1. Laragon Environment Information

| Component | Value |
|---|---|
| Stack manager | Laragon (`D:\laragon`) |
| MySQL | 8.4.3 (`D:\laragon\bin\mysql\mysql-8.4.3-winx64`), listening on `127.0.0.1:3306` |
| MySQL auth | `root` / empty password (default Laragon dev credentials) |
| Server charset / collation | `utf8mb4` / `utf8mb4_0900_ai_ci` |
| PHP | 8.3.30 (CLI, ZTS) |
| Node | v24.18.0 |
| npm | 11.16.0 |
| Backend | Laravel 13.20, served via `php artisan serve` on `127.0.0.1:8000` |
| Frontend | Vite dev server on `localhost:5173` |

The Windows `MySQL80` service (used in the prior validation attempt) was **not used**, per this sprint's instruction. `backend/laravel-api/.env` already pointed at `127.0.0.1:3306` with the `laravel` database, which Laragon's instance served correctly with no changes needed.

---

## 2. MySQL Validation Result — PASS

Ran `php artisan migrate:fresh --seed` against Laragon MySQL. All 36 migrations and 3 seeders (`RolePermissionSeeder`, `MasterDataSeeder`, `DocumentEngineSeeder`) completed with **zero errors**.

Verified directly against `information_schema`:

- **Charset/collation** — every table is `utf8mb4_0900_ai_ci` (server default), no mismatches.
- **UUID support** — every business table's primary key is `char(36)` as required by `.ia/04_DATABASE_GUIDELINES.md`. Framework tables (`jobs`, `sessions`, `personal_access_tokens`, `migrations`) correctly keep their Laravel-default key types.
- **Foreign keys** — 143 FK constraints present across the schema, all on `InnoDB` tables (confirmed `SHOW TABLE STATUS` / `information_schema.TABLES.ENGINE = InnoDB` for every table — required for both FK enforcement and transactions).
- **Indexes** — the Sprint 7 `add_dashboard_performance_indexes` migration applied cleanly (`accounts_payables.status`, `accounts_receivables.status`, `purchase_orders.order_date`, `sales_orders.order_date`, `goods_receipts.receipt_date`, `deliveries.delivery_date`, `items.current_stock`). All unique business codes (`item_code`, `supplier_code`, `customer_code`, `users.email`) are indexed via their `unique()` constraints.
- **Transactions** — verified implicitly: every multi-step write in the regression (Step 4 below) — PO submit, GR submit + stock ledger + AP creation, Payment submit + AP settlement — completed atomically with correct end states, and the deliberate overpayment attempt (Step 4) was rejected before any partial write occurred.

No MySQL-specific incompatibilities were found; nothing needed fixing at the database layer.

---

## 3. Authentication Validation — PASS

Tested directly against the live backend (no mocks):

| Check | Result |
|---|---|
| Login (`admin@example.com` / `password`, seeded by `RolePermissionSeeder`) | `200`, returns Sanctum token + user |
| `/auth/me` with valid token (session restore) | `200`, returns current user |
| Protected route without a token | `401` clean JSON |
| Logout | `200`, revokes the token |
| Reusing the token after logout | `401` (token correctly invalidated) |
| Frontend: login → redirect to `/dashboard` | Confirmed in-browser |
| Frontend: full page reload while authenticated (session restore) | Confirmed in-browser — stays authenticated, does not bounce to `/login` |

**Bug found and fixed:** an unauthenticated request to any protected route that omitted the `Accept: application/json` header (i.e. any client that isn't the frontend's axios instance — curl, Postman default, some tooling) returned a raw **500** with a full stack trace exposing server file paths, instead of the intended clean `401`. Root cause: Laravel's default `Authenticate` middleware calls `route('login')` to redirect guests when it doesn't detect a JSON-expecting request, and this is a pure API project with no `login` named route — so it threw `RouteNotFoundException`, which fell through the app's existing `AuthenticationException` JSON handler untouched.

Fix (`backend/laravel-api/bootstrap/app.php`):
```php
->withMiddleware(function (Middleware $middleware): void {
    // API-only app: no 'login' route exists to redirect guests to.
    $middleware->redirectGuestsTo(fn () => null);
})
```
This makes the middleware always throw `AuthenticationException` instead of attempting a redirect, which the app's existing exception handler already converts into a clean `{"success":false,"message":"Unauthenticated.","data":null}` / 401. Verified before and after with and without the `Accept` header.

---

## 4. Dashboard Validation — PASS

All five endpoints verified against Laragon MySQL, first against an empty database (all zeros/empty, matching an empty `items`/`customers`/`suppliers`/`purchase_orders`/etc. table set), then re-verified with real data produced by the Step 4 regression:

| Card | Value shown | Cross-check |
|---|---|---|
| Total Stock on Hand | 90 | 100 (GR-00001) − 20 (DN-00001) + 10 (GR-00002) = 90 ✓ |
| Zero Stock Items | 0 | Only tracked item has stock > 0 ✓ |
| Outstanding Payable | Rp 250.000 (1 invoice) | AP-00002 partially paid, 500.000 − 250.000 = 250.000 outstanding ✓ |
| Outstanding Receivable | Rp 0 | AR-00001 fully settled by REC-00001 ✓ |
| Low Stock Items (≤10) | empty | Stock is 90, above threshold ✓ |
| Recent Transactions | 9 rows, correct type/doc/date/amount/status, newest first | Matches every document created in the regression ✓ |

Confirmed both via direct API calls and rendered live in the browser (`/dashboard`) after logging in.

---

## 5. Full Workflow Regression — PASS

Driven end-to-end via the real API against Laragon MySQL (Company → Branch → Warehouse → Supplier/Customer → Item, then both workflows):

**Purchase:** Supplier → PO (100 × Rp 60.000 = Rp 6.000.000) → submit → Goods Receipt → submit → stock +100, AP-00001 created (unpaid, Rp 6.000.000) → Payment Entry (full amount) → submit → AP status `paid`, outstanding `0`.

**Sales:** Customer → SO (20 × Rp 75.000 = Rp 1.500.000) → submit → Delivery → submit → stock −20 (→80), AR-00001 created (unpaid) → Receipt Entry (full amount) → submit → AR status `paid`, outstanding `0`.

**Partial payment + business-rule regression (additional coverage):** second PO/GR cycle (10 × Rp 50.000 = Rp 500.000) → AP-00002 created → paid half (Rp 250.000) → status correctly transitions to `partially_paid`, outstanding `250.000` → dashboard AP-outstanding correctly reflects it → **attempted overpayment (Rp 999.999.999) correctly rejected with `422`** and a clear message ("paid_amount (999999999) exceeds outstanding payable (250000) for GR-00002."), no partial write occurred.

Stock ledger entries, AP/AR status transitions, and payment/receipt application all behaved correctly under real MySQL with real transactions/locking (`DB::transaction` + row locking in `DocumentNumberGeneratorService` and settlement paths).

---

## 6. Frontend Integration Status — PASS

Verified in-browser against the live Laragon-backed API (not mocked):

- Login form submits real credentials, receives a token, redirects to `/dashboard`.
- `AuthContext` restores the session correctly on a full page reload via `GET /auth/me`.
- Axios client (`shared/services/apiClient.ts`) attaches the Bearer token and reaches `127.0.0.1:8000/api/v1` correctly (`.env`'s `VITE_API_BASE_URL` needed no change).
- TanStack Query dashboard queries (`stock-summary`, `accounts-payable-outstanding`, `accounts-receivable-outstanding`, `low-stock-items`, `recent-transactions`) all resolved and rendered the exact values produced by the Step 4/5 regression (cross-checked above).
- Sidebar, header (with real user name "Admin"), and breadcrumb all rendered correctly on the authenticated layout.

No frontend integration bugs were found — no code changes were needed on the frontend side. Logout was not re-verified via UI click in this session due to browser-automation tooling flakiness (screenshot/CDP timeouts unrelated to the app), but is already proven correct at the API level (Section 3), and the frontend's logout handler is a trivial pass-through to that same endpoint.

---

## 7. Code Review (Step 6) Findings

Targeted review of `app/Http/Controllers`, `app/Http/Requests`, `app/Http/Resources`, `app/Services`, `app/Repositories`, `app/Models` for the categories requested:

| Category | Result |
|---|---|
| Broken imports | None found |
| Unused imports | None found (scripted check across all 6 layers) |
| Dead code / unused services | None — every `Service` class is reachable from a Controller or (in `DocumentNumberGeneratorService`'s case) via its bound interface, consumed by the `Documentable` model trait |
| Duplicate logic | `PaymentEntryService`/`ReceiptEntryService` (and similarly the Purchase/Sales pair) are near-identical mirrors of each other. This matches the project's existing intentional AP/AR and Purchase/Sales mirroring pattern throughout the codebase — not an oversight. Left as-is; a shared abstraction wasn't requested and would be a design decision, not a correctness fix. |
| Missing eager loading | None — every list/show repository method uses an explicit `EAGER` constant with `with()`, and every Resource that reads a relation uses `whenLoaded()` |
| Missing indexes | None — all FK columns (MySQL auto-indexes these), all unique business codes, and all dashboard query columns (Sprint 7 migration) are indexed |
| API inconsistencies | None — `{success, message, data, meta?}` envelope is consistent everywhere via the shared `ApiResponse` trait |
| **Bug found & fixed** | See Section 3 — unauthenticated requests without an `Accept: application/json` header crashed with a 500 stack-trace leak. Fixed in `bootstrap/app.php`. |

---

## 8. Git Repository Root

**Problem found:** this machine has a `.git` repository accidentally initialized at the **home directory** (`C:\Users\Zyrex`), unrelated to this project (its history is an old Flutter project). Both `backend/laravel-api` and `frontend` had no `.git` of their own, so any `git` command run from inside `Project_KE` was silently resolving up to that home-directory repo — meaning a `git add`/`git commit` from this project could have staged unrelated files from across the entire user profile (browser data, credentials directories, other projects, etc.).

**Fix applied:** initialized a proper repository at the correct root:

```
C:\Users\Zyrex\Documents\Project_KE\.git
```

Verified `git -C backend/laravel-api rev-parse --show-toplevel` and `git -C frontend rev-parse --show-toplevel` both now resolve to `Project_KE`, and `git status` from the project root shows only this project's own files. No commits were made (none were requested) — the repo is initialized but empty, ready for an initial commit when the user asks for one.

**Correct git root going forward: `C:\Users\Zyrex\Documents\Project_KE`.**

---

## 9. Remaining Technical Debt

- **`APP_DEBUG=true`** in `backend/laravel-api/.env` — correct for local development, but must be set to `false` before any non-local deployment (it renders full stack traces, which is what made the Section 3 bug visible as a path-leaking 500 instead of a generic error page).
- **`frondend/`** (note the typo) at the project root contains legacy static HTML prototypes (`legacy-form.html`, `legacy-stock-in.html`, etc.) kept only as UX/workflow reference per `.ia/00_PROJECT_CONTEXT.md`. It's dead weight in the repo long-term — worth deleting or moving under `docs/reference/` once nobody needs to compare against it, but out of scope to touch without confirmation.
- **`PaymentEntryService` / `ReceiptEntryService`** (and the Purchase/Sales service pair) are structurally duplicated — acceptable for now, but if a fourth mirrored domain is ever added, it's worth revisiting whether a shared base class pulls its weight.
- No automated test suite was exercised in this sprint (PHPUnit/Pest is installed but this validation was done via live HTTP regression, not `php artisan test`). Worth wiring the regression scenarios in this document into permanent feature tests so they run in CI rather than being re-derived by hand each time.
- Logout was not re-confirmed via a UI click in this session (browser automation tooling issue, not an app issue) — worth a quick manual click-through before considering auth fully closed out.

---

## 10. Recommended Next Sprint

Given the MVP is now validated as stable on the real target environment, and Frontend Sprint 1 (foundation) is already built and working:

1. **Master Data CRUD pages** (Company/Branch/Warehouse/Supplier/Customer/Item) — the natural next step per `.ia/03_ERP_BLUEPRINT.md`'s dependency order, and the shared `DataTable`/dialog components from Sprint 1 are ready to be reused.
2. Wire the regression scenarios from Section 5 into permanent Pest/PHPUnit feature tests, so this validation doesn't have to be repeated by hand next time.
3. Set `APP_DEBUG=false` and review `.env` hardening as part of whatever sprint first targets a non-local environment.
