# Frontend — Master Data Module

Phase 2B. Completes the Master Data module — Item (2A), Supplier, Customer, Warehouse, Item Group, and UOM (2B) — all built on the ERP Design System (`docs/ERP_DESIGN_SYSTEM.md`), which this sprint promoted from "the Item page's conventions" to an actually-extracted, actually-reused set of hooks and components.

---

## 1. Folder Structure

Everything lives under `frontend/src/features/master/`, one module for all six entities (not six separate feature folders — they're conceptually one "Master Data" section with one sidebar entry and one sub-nav):

```
features/master/
  navigation.ts                  masterDataNav — the six SectionNav entries
  types.ts                       every entity type + its <Entity>FormValues, in one file
  lib/
    itemFilters.ts               ItemFilterValues, applyItemFilters, emptyItemFilters
    supplierFilters.ts           SupplierFilterValues (is_active)
    customerFilters.ts           CustomerFilterValues (is_active)
    warehouseFilters.ts          WarehouseFilterValues (warehouse_type)
    itemGroupFilters.ts          search-only, no filter fields
    uomFilters.ts                search-only, no filter fields
  api/
    itemApi.ts / supplierApi.ts / customerApi.ts / warehouseApi.ts
    itemGroupApi.ts / uomApi.ts  each five lines on top of createCrudApi()
    lookupsApi.ts                fetchItemGroups / fetchUoms / fetchBranches — page-1 dropdown data
  components/
    ItemFormDrawer.tsx / ItemDetailDrawer.tsx / ItemFiltersBar.tsx
    SupplierFormDrawer.tsx / SupplierDetailDrawer.tsx / SupplierFiltersBar.tsx
    CustomerFormDrawer.tsx / CustomerDetailDrawer.tsx / CustomerFiltersBar.tsx
    WarehouseFormDrawer.tsx / WarehouseDetailDrawer.tsx / WarehouseFiltersBar.tsx
    ItemGroupFormDrawer.tsx / ItemGroupDetailDrawer.tsx      (no FiltersBar)
    UomFormDrawer.tsx / UomDetailDrawer.tsx                  (no FiltersBar)
  pages/
    ItemListPage.tsx / SupplierListPage.tsx / CustomerListPage.tsx
    WarehouseListPage.tsx / ItemGroupListPage.tsx / UomListPage.tsx
```

Shared infrastructure that now lives outside the feature folder, because it's used by more than Master Data will ever be:

```
shared/services/crudApi.ts       createCrudApi<T, TPayload>(basePath)
shared/services/lookupApi.ts     fetchLookupList<T>(path)
shared/hooks/useEntityListPage.ts
components/shared/PageHeader.tsx, ActionBar.tsx, RowActionsMenu.tsx,
  DetailDrawerLayout.tsx, SectionNav.tsx, StatusBadge.tsx (extended)
components/ui/switch.tsx
```

Routes in `app/router.tsx`: `/master/items`, `/master/suppliers`, `/master/customers`, `/master/warehouses`, `/master/item-groups`, `/master/uoms`. Sidebar's "Master Data" entry still points at `/master/items` (unchanged since 2A); the six pages navigate between each other via `SectionNav`, not the sidebar.

---

## 2. Reusable Components Added or Improved This Sprint

| What | Kind | Why it exists |
|---|---|---|
| `createCrudApi<T, TPayload>` | new, `shared/services/crudApi.ts` | Every entity's API module was the same five functions with different URLs — six copies made the pattern worth a factory |
| `fetchLookupList<T>` | new, `shared/services/lookupApi.ts` | Same page-1-dropdown-fetch logic needed for Item Group, UOM, and now Branch |
| `useEntityListPage<T, F>` | new, `shared/hooks/useEntityListPage.ts` | Absorbs the ~60 lines of page/search/filter/sort state + query + delete-mutation + drawer state that was identical across `ItemListPage` and would've been copy-pasted five more times |
| `SectionNav` | new, `components/shared/SectionNav.tsx` | Master Data went from one page to six — needed real navigation between siblings, not just the sidebar |
| `Switch` | new, `components/ui/switch.tsx` | Supplier/Customer's `is_active` boolean needed a toggle; built from the already-installed `radix-ui` package rather than adding a dependency |
| `StatusBadge` | extended | Added `main`/`transit`/`return` (Warehouse type) alongside the existing `in_stock`/`out_of_stock`/`active`/`inactive` styles |
| `Breadcrumbs` label fallback | fixed | Was capitalizing only the first character of multi-word route segments ("Item groups"); now title-cases each hyphen-separated word ("Item Groups") |
| `ItemListPage.tsx` | retrofitted | Rewritten on `useEntityListPage` + `createCrudApi`-based `itemApi.ts`, with no behavior change — proves the extraction against the one page that already had full manual QA, rather than trusting it blind on five new ones |

Full pattern documentation (with code examples) lives in `docs/ERP_DESIGN_SYSTEM.md` §7–9.

---

## 3. API Endpoints Integrated

| Entity | List | Create | Update | Delete | Dropdown/lookup data used |
|---|---|---|---|---|---|
| Item | `GET /items?page=N` | `POST /items` | `PUT /items/{id}` | `DELETE /items/{id}` | `/item-groups`, `/uoms` |
| Supplier | `GET /suppliers?page=N` | `POST /suppliers` | `PUT /suppliers/{id}` | `DELETE /suppliers/{id}` | — |
| Customer | `GET /customers?page=N` | `POST /customers` | `PUT /customers/{id}` | `DELETE /customers/{id}` | — |
| Warehouse | `GET /warehouses?page=N` | `POST /warehouses` | `PUT /warehouses/{id}` | `DELETE /warehouses/{id}` | `/branches` |
| Item Group | `GET /item-groups?page=N` | `POST /item-groups` | `PUT /item-groups/{id}` | `DELETE /item-groups/{id}` | — |
| UOM | `GET /uoms?page=N` | `POST /uoms` | `PUT /uoms/{id}` | `DELETE /uoms/{id}` | — |

No backend routes, controllers, or validation rules were touched — every one of the above already existed. `/item-groups` and `/uoms` do double duty: paginated CRUD for their own list pages, and page-1 lookup data for Item's form/Item's filters (via `lookupsApi.ts`, not the paginated `itemGroupApi.ts`/`uomApi.ts` — see §5 for why those are separate functions hitting the same URL).

---

## 4. CRUD Flow (identical shape across all six entities)

1. **List** — `useEntityListPage` fires `GET /<entity>?page=1` on mount, renders `DataTable` with skeleton rows while loading.
2. **Search / Filter** — typing in `SearchBox` or changing a `<Entity>FiltersBar` control re-runs `apply<Entity>Filters` client-side over the currently-loaded page (see `ERP_DESIGN_SYSTEM.md` §4 for why).
3. **Sort** — clicking a sortable column header toggles asc/desc, client-side over the same loaded page.
4. **Paginate** — `Pagination` calls `setPage`, which changes the `useQuery` key and fetches the next server page.
5. **View Details** — clicking a row (or "View" in the row's Actions menu) opens `<Entity>DetailDrawer`, built on `DetailDrawerLayout`, using data already in memory — no extra fetch.
6. **Create** — "New \<Entity\>" opens `<Entity>FormDrawer` in create mode (`entity` prop is `null`); submit calls `POST`, invalidates the list query, shows a success toast, closes the drawer.
7. **Edit** — the Actions menu's "Edit" (or the Detail Drawer's primary action) opens the same `<Entity>FormDrawer` pre-filled (`entity` prop set); submit calls `PUT`.
8. **Delete** — Actions menu's "Delete" opens the shared `DeleteDialog`; confirming calls `DELETE`, invalidates the list query, shows a success toast. Never fires on a single click — always requires the confirmation step.

Verified end-to-end against the real backend for Supplier (full create → view → delete cycle) and spot-checked for the other four (list rendering, filters, detail view — see deliverables screenshots).

---

## 5. Design Decisions & Trade-offs

- **One feature folder for six entities, not six.** Master Data is one sidebar section with one conceptual identity; splitting into `features/supplier/`, `features/customer/`, etc. would have scattered a module users experience as one thing. Entity-prefixed filenames (`SupplierFormDrawer.tsx`, not `FormDrawer.tsx` inside a `supplier/` folder) keep files findable without the extra directory nesting.
- **`fetchItemGroups`/`fetchUoms` (lookup, page 1) vs. `fetchItemGroupsPaged`/`fetchUomsPaged` (list page, respects `?page=`) are deliberately separate functions hitting the same URL.** They serve different callers with different expectations — a form dropdown wants "all of them, once," a list page wants "this page, refetch on page change." Collapsing them into one function would have made one caller's behavior depend on the other's assumptions.
- **Warehouse resolves its Branch name client-side.** `WarehouseResource` doesn't nest `branch` (unlike Item's `item_group`/`uom`), so both `WarehouseListPage` and `WarehouseDetailDrawer` run a `useQuery(['branches'], fetchBranches)` and look up the name by `branch_id`. React Query dedupes the request under the shared key, so this costs one extra network call per page load, not one per row. Flagged rather than silently worked around — see `ERP_DESIGN_SYSTEM.md`-style reasoning: not a bug (every other unfiltered master-data resource is equally minimal), just a gap this frontend closes on its own.
- **Item Group and UOM have no filter bar.** Both are pure name(+one optional field) lookup entities — there's no second dimension to filter on that Search doesn't already cover. Adding a filter control anyway would be decorative, not functional; the design system doc (§4) now documents this as the expected outcome for simple entities, not an oversight.
- **Status badges are real or honestly derived, never fabricated.** Supplier/Customer have a real `is_active` flag → badge reflects it directly. Warehouse has a real `warehouse_type` enum → badge shows it directly (doubles as useful information, not just a status indicator). Item Group/UOM have neither → their Detail Drawers render no badge at all, rather than inventing a meaningless "Active" that never changes.
- **`ItemListPage` was retrofitted onto the new hook/factory**, not left as-is. Leaving Item as the one page built differently from its five siblings would have made it a worse reference example going forward, and retrofitting against a page with existing manual QA coverage is safer than trusting the new abstraction on five untested pages first.

---

## 6. Future Extensibility Notes

- **Server-side filtering.** The day any master-data `Index<Entity>Request` gets added (Item is the most likely first candidate — real usage will outgrow 15 unfiltered items fastest), only that entity's `lib/<entity>Filters.ts` and `api/<entity>Api.ts` change. `useEntityListPage`, `<Entity>FiltersBar`, and the page's JSX are all already written to not care where filtering happens.
- **Bulk actions / row selection.** Not built — no requirement for it yet, and `DataTable` has no selection column. If a future module needs bulk delete/export, that's a `DataTable` extension (a `selectable` prop + checkbox column), not a per-page reinvention.
- **Export/Import.** Every page's `ActionBar` already reserves the button slots (disabled). Wiring one up for real is a matter of implementing the handler and deleting `disabled: true` — no layout or toolbar change needed on any page.
- **A generic `<Entity>FormDrawer` shell was considered and rejected this round** (same reasoning as 2A.1's `DetailDrawerLayout` decision) — six form bodies share a visual shell but differ enough in field types, dependent dropdowns (Warehouse's Branch), and cross-field validation that forcing a shared component would trade clarity for a small line-count win. Revisit if a seventh entity's form turns out to be structurally identical to an existing one.
- **Warehouse's branch-name resolution pattern** (fetch a lookup list, join client-side by id) is the template for any future entity whose backend Resource doesn't nest a relation it displays — Purchase Order lines referencing Items, for instance, may hit the same shape.

---

## 7. Screenshots

- Suppliers list (filter bar, Active badges): `screenshot-1784261027963-11.jpg`
- Customers list: `screenshot-1784260757985-7.jpg`
- Warehouses list (Branch resolved client-side, Type badges): `screenshot-1784261013550-10.jpg`
- Warehouse Detail Drawer (Branch + Type badge, confirmed via accessibility tree after a browser screenshot-capture glitch): see §4 verification notes
- Item Groups list (no filter bar, 5 seeded groups): `screenshot-1784261042021-12.jpg`
