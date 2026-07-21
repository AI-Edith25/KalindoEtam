# Frontend — Item Management Page

Phase 2A / 2A.1. First complete business page and the architectural/visual reference for every CRUD page built afterwards. This doc covers what's specific to Item — the reusable patterns it's built from are documented once in `docs/ERP_DESIGN_SYSTEM.md`.

---

## 1. Page Structure

Route: `/master/items` (sidebar: **Master Data**)

```
Breadcrumb (Master > Items)        ← AppLayout, automatic
PageHeader (title, count, ActionBar)
SearchBox + ItemFiltersBar
Data Table (sortable columns, row click / Actions menu → detail | edit | delete)
Pagination
```

Files, all under `frontend/src/features/master/`:

```
types.ts                          Item, ItemGroup, Uom, ItemFormValues
lib/itemFilters.ts                ItemFilterValues type, applyItemFilters(), hasActiveItemFilters()
api/itemApi.ts                    fetchItems, fetchItem, createItem, updateItem, deleteItem
api/lookupsApi.ts                 fetchItemGroups, fetchUoms (dropdown + filter data)
components/ItemFormDrawer.tsx     Create + Edit (one component, isEdit derived from `item` prop)
components/ItemDetailDrawer.tsx   Read-only detail view, built on the shared DetailDrawerLayout
components/ItemFiltersBar.tsx     Item Group / UOM filter controls (see §5 below)
pages/ItemListPage.tsx            List page — owns all page-level state, composes the shared layout pieces
```

Route wired in `frontend/src/app/router.tsx`; nav entry in `frontend/src/layouts/navigation.ts` (Master Data points directly at `/master/items`).

---

## 2. API Endpoints Used

| Action | Endpoint | Notes |
|---|---|---|
| List | `GET /items?page=N` | Paginated (`meta.current_page/per_page/total/last_page`); `page` is the only query param the backend reads |
| Create | `POST /items` | `{item_code, item_name, item_group_id, uom_id, standard_rate}` |
| Update | `PUT /items/{id}` | Same shape, partial via `sometimes` rules server-side |
| Delete | `DELETE /items/{id}` | |
| Item Group options | `GET /item-groups` | Page 1 only (see §5) — used for both the form dropdown and the filter bar |
| UOM options | `GET /uoms` | Page 1 only (see §5) — same dual use |

---

## 3. Reusable Components — Used by This Page

All defined once in `docs/ERP_DESIGN_SYSTEM.md` — Item is the first consumer of each:

- `PageHeader`, `ActionBar` — the header/toolbar row.
- `FilterPanel` (existing since Sprint 1) — wraps `ItemFiltersBar`'s Select controls.
- `DataTable`'s `sortKey` / `onRowClick` (added in 2A) — sortable columns, row click opens the detail drawer.
- `RowActionsMenu` — the row's `⋮` menu (View / Edit / Delete), replacing the two icon buttons from 2A.
- `DetailDrawerLayout` + `DetailField` + `DetailSection` — the Detail Drawer's shell.
- `DeleteDialog` — delete confirmation, unchanged since Sprint 1.

Item-specific, not shared (each future entity gets its own): `ItemFormDrawer`, `ItemDetailDrawer`, `ItemFiltersBar`, `lib/itemFilters.ts`.

---

## 4. UI Decisions

- **Drawer, not modal, for Create/Edit** — one `ItemFormDrawer` handles both; `isEdit = !!item` picks the mutation and copy.
- **Standard Rate is a string in the form**, converted to a number only in the mutation's `mutationFn` — avoids a `zod`/`@hookform/resolvers` type mismatch (`z.coerce.number()`'s input/output type split breaks `tsc -b` under this project's strictness). Reuse this for every future numeric field.
- **Status badge is stock-derived, not fabricated.** Item is master data with no lifecycle status field (unlike PO/SO/GR which have draft/submitted/cancelled). Rather than hardcode a meaningless "Active" that never changes, the Detail Drawer badge reads `current_stock > 0 ? 'in_stock' : 'out_of_stock'` — real data standing in for where a future status field would render. See `ERP_DESIGN_SYSTEM.md` for the pattern this establishes for entities that do get a real status later.
- **Export / Import are visible but disabled** — establishes the toolbar's final shape now so future pages match it from day one, without pretending the feature exists yet.

---

## 5. Known Limitation — Search & Filters Are Page-Local

`GET /items` (and `/item-groups`, `/uoms`) accept **no** `search`, `filter`, `sort`, or `per_page` query params — consistent across every master-data endpoint (Supplier, Customer, ItemGroup, Uom all share the same unfiltered `index()`), unlike the transactional endpoints (`AccountsPayable`, `AccountsReceivable`) which already have `Index*Request` filtering. Per the backend freeze on this sprint, search/sort/Item Group/UOM filters all operate **client-side over the currently-loaded page of 15 items**, not the full dataset.

This is intentionally isolated, not just accepted: `ItemFiltersBar` is a pure controlled component (`value`/`onChange`) that has no idea filtering happens client-side, and `applyItemFilters()` in `lib/itemFilters.ts` is the single seam where that happens. The day `IndexItemRequest` exists server-side, only `itemFilters.ts` and the query in `ItemListPage` change — `ItemFiltersBar`'s JSX doesn't.

The `ItemGroup`/`Uom` dropdown lookups have the same ceiling (page 1 / 15 records) — a non-issue at today's 5-each seed data, but will silently truncate if either list grows.

---

## 6. Future Reuse Guidelines

See `docs/ERP_DESIGN_SYSTEM.md` for the full CRUD page template. Short version for the next page (Supplier/Customer recommended): copy the folder shape, use `PageHeader`/`ActionBar`/`RowActionsMenu`/`DetailDrawerLayout` as-is, write a page-local `lib/<entity>Filters.ts` following the same isolation pattern, and use the string-then-convert trick for any numeric field.

---

## 7. Screenshots

- Item List (header count + toolbar + filter bar): `screenshot-1784258992593-5.jpg`
- Row Actions menu (View / Edit / Delete): `screenshot-1784259087171-6.jpg`
- Detail Drawer with status badge: `screenshot-1784258947960-4.jpg`
