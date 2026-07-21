# Master Data Module (Sprint 2)

Implements the remaining "Master" layer of `03_ERP_BLUEPRINT.md`: Customer, Supplier, Item Group, Unit of Measurement, Currency, Tax. Deliberately stops short of Purchase/Sales — per the sprint instruction, no transaction module is built until Master Data is complete.

## Layering

Identical pattern to Sprint 1: `Controller → Service → Repository → Model`, each repository extending `BaseRepository`, each service wrapping writes in `DB::transaction()`, every model using `HasUuids`, `SoftDeletes`, `HasAuditTrail`. No new architectural concepts were introduced — this sprint is intentionally "more of the same shape," not a new pattern.

## Entities

- **ItemGroup** / **UnitOfMeasurement**: flat reference lists. `Item` now has a real `item_group_id` / `uom_id` FK instead of the free-text `item_group` / `stock_uom` strings from Sprint 1 — see breaking-change note below.
- **Currency**: `code` (ISO-style), `exchange_rate` against a single implicit base. Not wired to `Company.currency` yet (still a plain string there) or to any transaction — this sprint only builds the master, not its consumers.
- **Tax**: `name` + `rate` (percentage). No fixed-amount tax type — nothing in scope needs it yet.
- **Customer** / **Supplier**: minimal contact masters (code, name, phone, email, address, `is_active`). No credit limit / payment terms fields — those are Sales/Purchase/Finance concerns and weren't asked for; `04_DATABASE_GUIDELINES.md`'s "Outstanding dihitung dari transaksi" means Piutang/Hutang will be computed from transactions once those modules exist, not stored here.

## Breaking change: Item.item_group / Item.stock_uom → FK

Sprint 1 shipped `Item.item_group` and `Item.stock_uom` as plain strings (there was no master for them yet). Now that `ItemGroup` and `UnitOfMeasurement` exist, `Item` was changed to reference them by `item_group_id` / `uom_id`. This was a deliberate, approved breaking change (confirmed before implementation — the alternative was leaving the masters disconnected from the one table that actually needs them). Safe to make directly in the existing migration: no environment in this project has a real MySQL database migrated yet (see `docs/DECISIONS.md#d-r09`), so there was no data to migrate.

`StoreItemRequest`/`UpdateItemRequest` now validate `item_group_id`/`uom_id` against `exists:item_groups,id` / `exists:uoms,id`. `ItemResource` embeds the related `ItemGroupResource`/`UomResource` when the relation is loaded; `ItemRepository::paginate()`/`findOrFail()` eager-load both by default so `index`/`show` always include them.

## Seeding

`MasterDataSeeder` (run after `RolePermissionSeeder` in `DatabaseSeeder`) seeds baseline reference data so the system is usable immediately: 5 UOMs (Pcs, Kg, Sak, Meter, Batang), 5 Item Groups (Semen, Besi, Cat, Pipa, Kayu — generic building-materials categories), the IDR currency, and a PPN 11% tax. Customer/Supplier are deliberately **not** seeded — they're real trading partners, not reference data, so inventing fake ones would just be noise.

`RolePermissionSeeder`'s module list was extended with `item_group`, `uom`, `currency`, `tax`, `customer`, `supplier`, so Admin automatically gets full CRUD permission on all six new modules (same `{module}.{view,create,update,delete}` convention as Sprint 1).
