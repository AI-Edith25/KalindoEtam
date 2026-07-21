# Implementation Decisions — Foundation, Inventory, Master Data, Document Engine, Purchase, Sales, Payment Workflow & Dashboard

Backend implementation-level decisions made while building this round, distinct from the project-level decisions in `.ia/08_DECISIONS.md`. Numbered `D-R##` to avoid clashing with that log's `D-00#` sequence.

## D-R01 — `users.id` converted to UUID

The default Laravel `users` migration used `bigIncrements`. Converted to `uuid` primary key (with `HasUuids` on the model) for consistency with the project-wide UUID rule, since every audit column (`created_by`/`updated_by`/`deleted_by`) and the `spatie/laravel-permission` morph key reference it. Safe to edit in place: no MySQL connection was available in this environment and no migration had ever run against a real database, so there was no data to migrate.

## D-R02 — `spatie/laravel-permission`, customized to UUID

Used the standard package rather than hand-rolling RBAC tables (D-004 implies dynamic role/permission management, which is exactly what this package is for). Customized `config/permission.php` (`model_morph_key = model_uuid`) and rewrote the published migration so `roles`/`permissions`/pivot tables use `uuid` instead of the package's default `bigIncrements`, matching the rest of the schema.

## D-R03 — Pivot tables excluded from audit trail / soft delete

`model_has_roles`, `model_has_permissions`, `role_has_permissions` do not get `created_by/updated_by/deleted_by` or `deleted_at`. They're composite-key pivots with no identity of their own — "semua entity" (all entities) is read as all standalone business/master tables, not join tables.

## D-R04 — `fiscal_year_start` as a single date column

Company gets one `date` column rather than a full Fiscal Year entity (start/end, multiple periods, closing). ERPNext models this as its own doctype; simplified here since Accounting module isn't built yet and nothing in `.ia` specifies multi-period fiscal year behavior. Revisit when the Accounting module is scoped.

## D-R05 — Supplier & "Foto Barang" deferred

The Barang Masuk diagram in `01_BUSINESS_REQUIREMENTS.md` includes Supplier and a photo step. Both belong to the Master/Purchase layer per the blueprint ordering, which wasn't in this round's approved scope (Foundation + Inventory refactor only). `StockIn` stays shape-compatible with today's simple item+warehouse+qty; `supplier_id`/`photo_path` get added when Master/Purchase is scoped, not retrofitted speculatively now.

## D-R06 — Permission naming: `{module}.{action}`

Permissions are seeded strings (`company.view`, `item.create`, …), not a closed PHP enum — Admin is expected to manage permission-to-role assignment dynamically (D-004), so the set of permission *names* isn't a fixed enumeration the way `WarehouseType` or `StockTransactionType` are. The `/permissions` endpoint is read-only; new permission names are added by seeding when a new module ships, not created ad-hoc through the API.

## D-R07 — `StockLedgerService` balance locking

`latestBalance()` uses `lockForUpdate()` so two concurrent Stock In requests against the same item+warehouse don't read a stale balance. This only locks an *existing* row — the very first ledger entry for a given item+warehouse has nothing to lock against, so a race on that specific first-write is still theoretically possible. No unique-constraint or advisory-lock workaround was added for that edge case; nothing in `.ia` calls out concurrent-write throughput as a requirement. Revisit if it becomes one.

## D-R08 — Observer registration deferred via `whenBooted()`

`HasAuditTrail::bootHasAuditTrail()` originally called `static::observe(AuditableObserver::class)` directly. That throws `LogicException: ...bootIfNotBooted... while it is being booted`, because Laravel's `Model::observe()` does `new static` internally, which re-enters the boot sequence for the same class while its `$booting` flag is still set. Fixed by wrapping the call in `static::whenBooted(fn () => static::observe(...))`, the same pattern Laravel core itself uses for the `#[ObservedBy]` attribute. Caught via the migrate+seed sanity check, not a hypothetical.

## D-R09 — Verified against SQLite, not the project's MySQL

The local `MySQL80` Windows service exists but is stopped, and starting it requires admin privileges this session doesn't have. Rather than elevate, `migrate:fresh --seed` and an end-to-end smoke test (Company → Branch → Warehouse → Item → StockIn → StockLedger) were run against a throwaway SQLite file via inline env vars, without touching the project's real `.env`. The schema and business logic are database-agnostic (no MySQL-specific SQL used), so this is a valid sanity check, but **the real MySQL database has not been migrated** — start `MySQL80` and run `php artisan migrate --seed` before using the API.

## D-R10 — `current_stock` removed from Item write requests

`StoreItemRequest`/`UpdateItemRequest` no longer accept `current_stock` as input (it was previously a plain editable field). Enforces "Current Stock hanya cache" at the boundary: the only writer is `StockLedgerService`.

## D-R11 — `ItemGroup` is flat, no parent/child tree

ERPNext's Item Group is a tree (nested categories). Sprint 2 ships it flat — `name` + `description`, no `parent_id`. Nothing in `.ia` or the sprint scope calls for nested categories yet, and a self-referencing tree adds real complexity (recursive queries, cycle prevention) for a need that hasn't shown up. Adding `parent_id` later is a single additive migration, not a redesign.

## D-R12 — `Item.item_group` / `Item.stock_uom` converted to FK (breaking change)

Confirmed explicitly before implementation (Sprint 2 kickoff). `items.item_group` (string) → `item_group_id` (FK), `items.stock_uom` (string) → `uom_id` (FK). Done by editing the existing `items` migration in place, with two new migrations (`item_groups`, `uoms`) timestamped just before it so creation order holds. No real MySQL data exists yet (D-R09), so there was nothing to backfill. `StoreItemRequest`/`UpdateItemRequest` and `ItemResource` were updated to match; `ItemRepository` now eager-loads `itemGroup`/`uom` on every read so the API always returns the related object, not just the raw FK.

## D-R13 — `NamingSeries.document_type` is not unique; `is_default` is an app-level invariant

Multiple `NamingSeries` rows can share the same `document_type` (e.g. an alternate series for a branch, later). The generator always resolves `document_type + is_default=true + is_active=true`. "Only one default per type" is enforced nowhere at the DB level — no partial unique index — because MySQL can't express that cleanly without a generated-column trick, and the practical risk (an admin misconfiguring two defaults) is caught by `firstOrFail()`/`first()` behavior being deterministic-but-arbitrary, not a crash. Revisit with a real constraint if misconfiguration turns out to happen in practice.

## D-R14 — `DocumentNumberGeneratorInterface` bound in `AppServiceProvider`

First interface in the codebase (`.ia` doesn't otherwise call for interfaces — every other Service is a concrete class consumed directly, per the existing pattern). Added because Sprint 3 explicitly asked for it: `app/Contracts/DocumentNumberGeneratorInterface.php`, bound to `DocumentNumberGeneratorService` via `$this->app->bind(...)` in `AppServiceProvider::register()`. The `Documentable` trait resolves the interface (`app(DocumentNumberGeneratorInterface::class)`), not the concrete class, so a future alternate numbering strategy is a one-line rebind, not a trait rewrite.

## D-R15 — `ApprovalFlow` (renamed from `Approval`): structure only, no layers above Model

Per explicit instruction ("belum implementasi workflow, hanya struktur"): migration + model + `ApprovalStatus` enum, and nothing else — no Repository, Service, Controller, or route. Building a CRUD API for a feature with zero business logic behind it would look functional without being functional. The `Documentable` trait exposes `approvalFlows()` (morphMany) so the relation is ready the moment a future sprint adds the workflow, but nothing writes to it yet.

## D-R16 — Document Engine verified against a throwaway test model, not a real doctype

Purchase/Sales/Invoice/Journal don't exist yet, so there's no real `Documentable` consumer to test against. Verification defined an anonymous Eloquent model backed by a temporary `smoke_test_documents` table (created and dropped entirely inside the smoke-test script, never committed), exercising the full lifecycle: auto-numbering via the interface, `Draft` default, `submit()`/`cancel()` guards and timestamps, `afterCreate`/`afterSubmit`/`afterCancel` override resolution, and `DocumentTimeline` recording. This proves the trait mechanics work; it does not substitute for testing against a real future document type once one exists.

## D-R17 — `DocumentAttachment`/`DocumentTimeline` `*_type` columns store raw class names, no morph map

`Relation::morphMap()` was considered but not added: with zero real `Documentable` consumers this sprint, an empty or placeholder map is pure ceremony — nothing to alias yet. `attachable_type`/`subject_type` are validated as plain required strings for now. Add the morph map (and tighten validation to `Rule::in(array_keys(...))`) when the first real document type (Purchase) is registered — deferred, not skipped.

## D-R18 — `DocumentAttachment.uploaded_by` duplicates `created_by`

Both columns hold the same user ID in practice (`HasAuditTrail` sets `created_by` on `creating`; the service explicitly sets `uploaded_by` from `Auth::id()` too). Kept as an explicit, separately-named column per instruction, since "who uploaded this file" reads clearer on an attachment than the generic audit-trail `created_by` — not a data-integrity concern since both are set from the same source at the same moment.

## D-R19 — `due_date` is manually entered on Goods Receipt, no payment-terms field anywhere

Confirmed explicitly before implementation (Sprint 4 kickoff question). No `payment_term_days` on `Supplier`, no Net-30 or any other default. `AccountsPayable.due_date` is a plain copied value from `GoodsReceipt.due_date`, validated only as `after_or_equal:receipt_date`. Adding real payment-terms logic later is additive (a new Supplier field + a default-calculation step before the manual-entry fallback), not a schema change to `AccountsPayable` — the column already exists and already means "when this is due," regardless of how it gets populated.

## D-R20 — `BusinessException` used for every Sprint 4 service-layer guard, not just the one instruction named

The instruction explicitly required it only for `GoodsReceipt::cancel()`. Sprint 3's `Documentable::submit()`/`cancel()` guards use Laravel's `abort_if()` (→ `HttpException`, default Laravel error shape, not the project's `{success, message, data}` envelope) — a pre-existing inconsistency, left alone since touching it wasn't asked for. For every *new* guard introduced this sprint (draft-only update/delete, PO-not-submitted, over-receipt, PO-cancel-with-receipts), `BusinessException` was used uniformly instead of mixing it with `abort_if()` in the same sprint's new code — that would be inconsistent for no reason. `BusinessException::render()` produces the correct project-standard envelope at a configurable status (default 422). Revisit Sprint 3's `abort_if()` calls in a later cleanup pass if the inconsistency ever causes an actual problem for API consumers.

## D-R21 — Snapshot fields limited to `GoodsReceiptItem`, not `PurchaseOrderItem`

Per instruction, only `GoodsReceiptItem` snapshots `item_code`/`item_name`/`uom` (`PurchaseOrderItem` still reads them live via the `item` relation). Rationale: a Goods Receipt is the permanent record of what physically happened and what money is owed — it must stay accurate even if the Item master changes years later. A Purchase Order is a shorter-lived plan that either gets received (superseded by the Goods Receipt's snapshot) or cancelled; nothing downstream depends on its line data staying frozen forever.

## D-R22 — Bug caught during verification: `status` cast missing on `PurchaseOrder`/`GoodsReceipt`

Both models initially omitted `'status' => DocumentStatus::class` from `$casts`, so `$model->status` returned a raw string after a fresh load while `Documentable::submit()`/`cancel()` compare against the `DocumentStatus` enum with `!==`. This made `submit()` on a genuinely-Draft document fail with "Only draft documents can be submitted." Caught immediately by the smoke test (first `submit()` call failed), fixed by adding the cast to both models. Recorded here as a concrete reminder — per `docs/DOCUMENT_ENGINE.md`, **every** model using `Documentable` must cast `status` to `DocumentStatus`; this is easy to forget because the trait itself can't enforce it (casts are declared per-model, not injectable by a trait). Sprint 5's `SalesOrder`/`Delivery` got the cast right from the start, specifically because of this note — the smoke test's first `submit()` call passed immediately.

## D-R23 — `AccountsReceivableStatus` is a separate enum from `AccountsPayableStatus`, despite identical values

Both are currently `Unpaid`/`PartiallyPaid`/`Paid`. Considered sharing one enum (e.g. a generic `SettlementStatus`) to avoid the duplication, but kept them separate: Payable and Receivable are independently-evolvable domain concepts (ERPNext doesn't merge them either), and a future need specific to one — e.g. a `Disputed` receivable status, or an `Overdue` payable flag — would otherwise force an awkward choice between polluting the shared enum with a case that only applies to one side, or splitting it apart later anyway (a breaking change to every column already cast to it). Three duplicated string cases is a smaller cost than that.

## D-R24 — `getCurrentBalance()` added to `StockLedgerService`, not queried directly from the repository

Sprint 4 never needed to *read* a stock balance mid-workflow (receiving can't go negative). Sprint 5's Delivery does, to guard against shipping more than physically exists. Rather than have `DeliveryService` reach into `StockLedgerRepository::latestBalance()` directly — which would create a second, inconsistent path for reading stock alongside `StockLedgerService`'s existing role as the only path for *writing* it — `StockLedgerService` gained a thin `getCurrentBalance()` wrapper. Keeps the "one gateway for all stock interaction" property from Sprint 1's D-004-adjacent design intact for reads, not just writes.

## D-R25 — Insufficient-stock guard on Delivery: added beyond the literal spec, confirmed before implementation

The Sprint 5 instruction only specified `delivered_qty <= outstanding_qty` (a Sales-Order-side check). It did not ask for a physical-stock check. Confirmed explicitly before implementation that this was worth adding anyway: without it, `StockLedgerService::record()` would happily drive a warehouse balance negative if a Delivery was submitted for stock that was never actually received (or already shipped elsewhere) — a `qty_change`/`balance_qty` row that's simply wrong, not just business-rule-violating. `DeliveryService::assertSufficientStock()` checks `StockLedgerService::getCurrentBalance() >= qty` per line before any stock is moved. See known limitations in `docs/SALES_WORKFLOW.md` for the narrow concurrent-submit race this doesn't close.

## D-R26 — `DeliveryItem` snapshots `rate` in addition to the descriptive fields Sprint 4 snapshotted

Sprint 4's `GoodsReceiptItem` snapshots `item_code`/`item_name`/`uom` but reads `rate` live from `PurchaseOrderItem` (never modified after creation in practice, but not literally frozen). Sprint 5's instruction explicitly lists `rate` among `DeliveryItem`'s snapshot fields, so `DeliveryItem.rate` is copied from `SalesOrderItem.rate` at delivery-creation time. This is a deliberately closer historical guarantee than Sprint 4's — worth noting so a future Purchase Workflow revision doesn't assume the two sprints' snapshot scope was meant to match exactly.

## D-R27 — `PaymentEntry`/`ReceiptEntry` forbid `cancel()`, applying Sprint 4/5's precedent without being asked again

Sprint 6's instruction didn't explicitly say "Payment Entry cannot be cancelled" the way Sprint 5 explicitly said it for `Delivery`. Applied the identical guard anyway — override `cancel()` to throw `BusinessException`, no cancel route — because it's the same underlying situation already established and approved twice: a submitted document with a real side effect on another table (AP/AR balance, same as stock) and no reversal workflow. Treating this as a fresh judgment call requiring a fresh question would have been inconsistent with "reuse every architecture… do not duplicate business logic" — the point of that instruction is that this class of problem already has an answer in this codebase. Flagged in the implementation summary rather than asked about beforehand, unlike D-R25 (the insufficient-stock guard), which was a genuinely new mechanic with no prior instance to point to.

## D-R28 — Duplicate AP/AR reference within one settlement entry is rejected, for correctness, not style

Neither the Sprint 6 instruction nor any prior sprint mentioned this. Added because allowing it is a real bug, not a style preference: `PaymentEntryService::create()` validates each line's `paid_amount` against the referenced `AccountsPayable`'s outstanding balance independently. Two lines against the *same* AP within one request would each pass that check against the same starting outstanding value, and their sum could exceed it — the per-line validation has no visibility into sibling lines targeting the same record. Rejecting duplicates outright (`assertNoDuplicateReferences()`) is simpler and safer than accumulating a running total across lines referencing the same AP within a single request. A user wanting to pay one AP in "two amounts" should just combine them into one line.

## D-R29 — `SettlementStatus` is a plain static helper in `app/Support`, not a Service

`App\Support\SettlementStatus::resolve(amount, paidAmount): string` has no dependencies, no side effects, and isn't tied to Eloquent — it's pure arithmetic-then-branch (three comparisons). Putting it through the Repository/Service layers used by every stateful entity in this codebase would be ceremony around a function that could be a one-line match expression. `app/Support` is a new top-level directory (no prior precedent in this codebase for a non-Model/Service/Repository class) — introduced because nothing existing fit: it's not a Model, Service, Repository, Enum, Contract, Trait, or Exception, and forcing it into one of those categories (e.g. a static method on one of the two Services, then called cross-service by the other) would have re-introduced exactly the duplication `AccountsReceivableStatus` being a separate enum (D-R23) was designed to avoid leaking into.

## D-R30 — `PaymentMethod` is a single enum shared by `PaymentEntry` and `ReceiptEntry`

Unlike `AccountsPayableStatus`/`AccountsReceivableStatus` (D-R23, deliberately kept separate), `PaymentMethod` is genuinely the same concept on both sides — "how did money move" doesn't have a payable-specific or receivable-specific variant, and there's no plausible future case where Cash/BankTransfer/Cheque would need to diverge between the two. Sharing it here isn't in tension with D-R23's reasoning; that decision was about two *status* concepts that happen to have identical values today but could reasonably diverge, not a blanket "never share enums" rule.

---

# Sprint 7 — Integration & Dashboard Readiness

Sprint 7 was explicitly a stabilization sprint, not a new-module sprint. The decisions below are almost all "found a real bug during review, fixed it" rather than new design choices — the review methodology and evidence are in `docs/INTEGRATION_CHECKLIST.md`; these entries record *why* each fix was made the way it was.

## D-R31 — `Item.current_stock` was silently wrong for any item stocked in more than one warehouse

**The bug**: `StockLedgerService::record()` called `$this->itemRepository->updateCurrentStock($item, $balanceQty)`, where `$balanceQty` is the running balance for the *single* item+warehouse pair just transacted (correct for the `StockLedger` row itself, wrong as a value for `Item.current_stock`). Every write overwrote the item's cache with whichever warehouse was most recently touched, discarding whatever balance existed in every other warehouse. Present since Sprint 1; never caught because every smoke test through Sprint 6 used exactly one warehouse.

**The fix**: `StockLedgerRepository::totalBalanceForItem($itemId)` sums `latestBalance()` (already-tested, unchanged) across every warehouse the item has ledger entries in; `StockLedgerService::record()` now calls this instead of passing the single-warehouse `$balanceQty` to the cache update. Verified with a smoke test that receives stock into two different warehouses and confirms `current_stock` reflects the sum, not the last write. See `docs/ERD.md`'s `ITEMS.current_stock` note, which had (incorrectly) already documented "summed" behavior that the code didn't actually implement until this fix.

## D-R32 — Pagination metadata was silently dropped by every list endpoint in the API

**The bug**: `ApiResponse::success()` wraps a `ResourceCollection` inside a hand-built `response()->json(['data' => $collection, ...])` array. Laravel only attaches a paginated resource's `links`/`meta` (page number, total, per-page) when the framework calls `toResponse()` on a `ResourceCollection` returned *directly* from a controller action — wrapping it inside another array and calling `response()->json()` instead triggers plain `jsonSerialize()`, which returns only the bare data array. Confirmed empirically (not just reasoned about) by inspecting actual JSON output before and after the fix. This affected **every** `index()` endpoint in the API since Sprint 1 — a frontend had no way to know how many pages existed or which page it was viewing.

**The fix**: `ApiResponse::success()` now detects `$data instanceof ResourceCollection` wrapping a `LengthAwarePaginator` and adds a top-level `meta: {current_page, per_page, total, last_page}`. Chosen over switching to Laravel's native `toResponse()` pattern (returning resources directly from controllers) because that would abandon the project's mandated `{success, message, data}` envelope (`.ia/05_API_GUIDELINES.md`) — the fix had to extend the existing envelope, not replace the response mechanism.

## D-R33 — `whereDate()` instead of `where()` for the new dashboard date-range queries

**The bug** (caught by the Sprint 7 smoke test, not by inspection): `PurchaseOrderRepository::totalForDate()`/`SalesOrderRepository::totalForDate()` originally used `where('order_date', $date)` with a plain `'Y-m-d'` string. `order_date` is cast `'date'` on the Eloquent model, which only affects PHP-side accessor behavior (truncating time when read back) — the value Eloquent actually *writes* to the database follows the connection's datetime format. On SQLite (this project's only tested environment so far, per D-R09) that write includes a `00:00:00` time component, so an exact string match against `'2026-07-22'` never hits. MySQL's native `DATE` column type would have masked this (it structurally cannot store a time part), which is exactly why the smoke test — run against SQLite — caught something MySQL testing might not have.

**The fix**: `whereDate('order_date', $date)`, which generates a `DATE(column) = ?`-style comparison portable across both engines, replacing the fragile exact-string match. Applied to both repositories' `totalForDate()`.

## D-R34 — `low-stock-items` takes `threshold` as a query parameter; no `minimum_stock` column added to `Item`

The dashboard objective explicitly listed "Low Stock Items" as an example endpoint, but `Item` has no per-item reorder threshold field, and adding one is schema growth this stabilization sprint's own instructions rule out ("do not introduce new features"). Rather than invent an arbitrary global constant (e.g. hardcoding "low = below 10"), `GET /dashboard/low-stock-items?threshold=N` takes the threshold from the caller, defaulting to 10 when omitted. A real `minimum_stock`-per-item feature, if it turns out to be needed, is a small additive migration + a small change to this one query — not a redesign.

## D-R35 — Eager-loading audit found no header/`show()` inconsistencies; two real N+1s found and fixed anyway

Systematically compared every workflow controller's `show()` eager-load list against its Repository's `index()`/`paginate()` eager-load constant (`PurchaseOrderController` vs `PurchaseOrderRepository::EAGER`, and the same for Goods Receipt, Sales Order, Delivery, Payment Entry, Receipt Entry, Accounts Payable, Accounts Receivable, Item) — all nine pairs matched exactly, so no fix was needed there. Two smaller, real N+1s were found and fixed instead: `PurchaseOrderItemRepository`/`SalesOrderItemRepository::findOrFail()` didn't eager-load `item.uom`, so `GoodsReceiptService::addLine()`/`DeliveryService::addLine()` lazy-loaded it once per line when building the item-code/name/uom snapshot (bounded by lines-per-request, not table size, but still avoidable — fixed by adding the eager load to both repositories' `findOrFail()`). A third, lower-severity case (`PurchaseOrderItem.item` accessed only inside a `BusinessException` message string, i.e. only on the already-slow error path) was identified and left as-is — optimizing a query that only runs when the request is about to fail anyway isn't worth the extra eager-load weight on the success path.

## D-R36 — Seven new indexes added; none required a data or behavior change

`accounts_payables.status`, `accounts_receivables.status`, `purchase_orders.order_date`, `sales_orders.order_date`, `goods_receipts.receipt_date`, `deliveries.delivery_date`, `items.current_stock` had no index despite each being the primary filter column of a Sprint 7 dashboard query (outstanding-by-status, today's-total-by-date, low-stock-scan) — and, for `status`/`order_date`, of pre-existing Sprint 4/5 filters (`AccountsPayableRepository::search()`, etc.) that predate this sprint. Pure schema addition (one migration, `up()`/`down()` both trivial), zero query-result changes, safe to ship without further review.
