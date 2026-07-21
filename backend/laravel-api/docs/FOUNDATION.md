# Foundation Module

Implements the base layer of `03_ERP_BLUEPRINT.md`: Company → Branch → Warehouse, plus Role & Permission. Also brings the pre-existing Item/StockIn module up to the same standard and introduces Stock Ledger as the source of truth for stock, per `04_DATABASE_GUIDELINES.md`.

## Layering

Every write path follows `Controller → Service → Repository → Model` (`02_SYSTEM_ARCHITECTURE.md`). Controllers only translate HTTP ⇄ Service calls; no business rule lives in a Controller or a Model. `BaseRepository` (`app/Repositories/BaseRepository.php`) holds the generic CRUD (`paginate`, `findOrFail`, `create`, `update`, `delete`) that every entity repository extends — added once seven repositories needed the identical calls, not speculatively.

## Company / Branch / Warehouse

- **Company**: top-level org unit. Carries `currency`, `timezone`, `fiscal_year_start` — enough for the Accounting module later without building a full Fiscal Year entity now (deferred, see DECISIONS.md).
- **Branch**: belongs to a Company. `is_head_office` flags the branch used as the default when a transaction doesn't specify one (not yet enforced anywhere — no module needs that default yet).
- **Warehouse**: belongs to a Branch. `warehouse_type` (`WarehouseType` enum: `main`, `transit`, `return`) distinguishes stock locations the way ERPNext's Warehouse Type does, simplified to three fixed cases instead of a free-form doctype.

## Roles & Permissions

Built on `spatie/laravel-permission`, customized so `roles`, `permissions`, and their pivot morph key all use **UUID** (config: `column_names.model_morph_key = model_uuid`), matching the project-wide UUID rule. `App\Models\Role` / `App\Models\Permission` extend the package's models to add `HasUuids`, `SoftDeletes`, and the audit trail.

Per D-004 (`.ia/08_DECISIONS.md`): **Admin is the first role**, seeded — not hardcoded into application logic — by `RolePermissionSeeder`. It generates `{module}.{action}` permissions for every module in this round (`company`, `branch`, `warehouse`, `role`, `item`, `stock` × `view/create/update/delete`) and grants all of them to Admin. A default `admin@example.com` user is created and assigned the role, so the seeded system is immediately usable. Further roles are created through the `/roles` API, not through code.

## Stock: single entry point

**Every** inventory transaction must go through `StockLedgerService::record()` (revision requirement). `StockInService` does not write ledger rows itself — it creates the `StockIn` header/line, then calls `StockLedgerService::record()`, which:

1. Locks and reads the item+warehouse's latest `balance_qty` (`lockForUpdate`, to serialize concurrent writes against the same item/warehouse — see DECISIONS.md for its limits).
2. Computes the new balance and inserts the `StockLedger` row (the source of truth).
3. Writes that balance into `Item.current_stock` — a cache, never edited directly by any request (`StoreItemRequest`/`UpdateItemRequest` do not accept `current_stock` as input).

Any future transaction type (Stock Out, Transfer, Purchase Receipt, Delivery) is expected to call the same service rather than writing to `stock_ledgers` directly — that's the whole point of centralizing it.

## Audit trail (created_by / updated_by / deleted_by)

`app/Models/Concerns/HasAuditTrail.php` is a trait applied to every entity model. Its `bootHasAuditTrail()` registers `App\Observers\AuditableObserver`, which:

- sets `created_by` on `creating`,
- sets `updated_by` on `updating`,
- sets `deleted_by` then quietly saves before the soft-delete on `deleting`.

The registration is wrapped in `static::whenBooted(...)` rather than called directly — calling `static::observe()` synchronously inside a `bootXxx()` method recurses into `new static()` while the model is still mid-boot and Laravel throws `LogicException`. This was caught during the migration/seed sanity check (see DECISIONS.md).

Pivot tables (`model_has_roles`, `model_has_permissions`, `role_has_permissions`) do **not** carry these columns — they're composite-key pivots, not entities with an identity of their own.

## Transactions

Every write in every Service is wrapped in `DB::transaction()`, including single-row CRUD — per explicit requirement, not just multi-step operations.
