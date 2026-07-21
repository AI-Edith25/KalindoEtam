# ERP Design System

Extracted from the Item Management page (Phase 2A / 2A.1) and proven out across the full Master Data module (Phase 2B: Supplier, Customer, Warehouse, Item Group, UOM). This is the template every future CRUD page (Purchase, Sales, ...) should be built from — copy the shape, don't reinvent it per page.

---

## 1. CRUD Page Structure

Every list-style business page follows the same vertical stack:

```
Breadcrumb                          ← AppLayout, automatic from the route — never rendered per-page
PageHeader                          title · record count · ActionBar
Search + Filter row                 SearchBox · entity-specific FilterPanel controls
Data Table                          sortable columns, row click → Detail Drawer, row Actions menu
Pagination
```

Below the table: a Form Drawer (create/edit), a Detail Drawer (view), and a Delete confirmation — all mounted at the bottom of the page component, opened/closed via local `useState`, never routed to separate URLs.

Reference implementation: `frontend/src/features/master/pages/ItemListPage.tsx`.

---

## 2. Header Layout

Component: `components/shared/PageHeader.tsx`

```tsx
<PageHeader
  title="Items"
  description="Manage the item catalog used across purchase, sales, and inventory."
  count={`${total} items`}
  actions={<ActionBar ... />}
/>
```

- **Title** — plural entity name, matches the sidebar label.
- **Description** — one sentence, what the page is for.
- **Count** — total record count from the API's `meta.total` (the whole dataset, not just the loaded page). Omit the prop entirely while the count isn't known yet (loading) rather than showing a stale or zero value.
- **Breadcrumb is deliberately not part of this component.** `AppLayout` already renders one above every page's content, generated from the URL segments (`layouts/Breadcrumbs.tsx`). Adding a second one here would duplicate it.

---

## 3. Toolbar Layout (Action Bar)

Component: `components/shared/ActionBar.tsx`

```tsx
<ActionBar
  actions={[
    { label: 'Refresh', icon: RotateCw, onClick: refetch, disabled: isFetching },
    { label: 'Export', icon: Download, disabled: true },
    { label: 'Import', icon: Upload, disabled: true },
  ]}
  primary={{ label: 'New Item', icon: Plus, onClick: openCreate }}
/>
```

- `actions[]` render first, as outline buttons, in the order given.
- `primary` renders last, as the solid/default-variant button — the one create action every list page has.
- **Every future CRUD toolbar ships with Export and Import from day one, disabled (`disabled: true`)**, not omitted. This keeps the toolbar's shape and width consistent across pages before those features exist, and turning one on later is a one-line change (delete `disabled: true`), not a layout change.
- Order convention: secondary/read actions (Refresh, Export, Import) → primary create action, left to right.

---

## 4. Filter Layout

Two-part pattern, deliberately split so the UI never has to change when the filtering strategy does:

1. **A pure controlled filter-bar component** (e.g. `features/master/components/ItemFiltersBar.tsx`) — takes `value`/`onChange`, renders `Select` controls wrapped in the existing `components/shared/FilterPanel.tsx` shell (search icon, "Clear filters" button when any filter is active). It has zero knowledge of *how* the value it emits gets applied.
2. **A pure filter function** in a page-local `lib/<entity>Filters.ts` (e.g. `features/master/lib/itemFilters.ts`) — `apply<Entity>Filters(items, search, filters)`. This is the one place that knows filtering is currently client-side.

```tsx
// UI — reusable shape, entity-specific fields
<ItemFiltersBar value={filters} onChange={setFilters} />

// Logic — isolated, swappable
const rows = applyItemFilters(itemsQuery.data?.data ?? [], search, filters)
```

**Why this split matters:** none of the master-data list endpoints (`Item`, `Supplier`, `Customer`, `ItemGroup`, `Uom`, `Warehouse`) accept `search`/`filter`/`sort` query params yet — only the transactional endpoints (`AccountsPayable`, `AccountsReceivable`) have `Index*Request` filtering today. Every future master-data page will hit the same wall. When a page's dataset outgrows client-side filtering and the backend adds `Index<Entity>Request`, only that page's `lib/<entity>Filters.ts` and its `useQuery` call change — the `<Entity>FiltersBar` component and its JSX in the page stay untouched.

`SearchBox` (existing since Sprint 1) is reused as-is and sits next to the filter bar, not inside it — search is a distinct, always-present control; entity-specific filters are additive.

**A filter bar is optional, not mandatory.** Item Group and UOM have no dimension worth filtering on beyond what Search already covers (name/description, name/symbol) — they render `SearchBox` alone. Forcing a filter control onto an entity with nothing to filter is worse than skipping it: an empty/meaningless dropdown reads as broken, not thorough. When an entity has no filter bar, its `lib/<entity>Filters.ts` still exists (for the search-only `apply<Entity>Filters` function and an empty `<Entity>FilterValues = Record<string, never>` type expected by the shared hook — see §9), it just has no matching `<Entity>FiltersBar.tsx`.

---

## 5. Table & Row Actions

`components/shared/DataTable.tsx` (extended in Phase 2A, unchanged since):

- `columns[].sortKey` — makes a column header clickable with an asc/desc/unsorted icon. Sorting is client-side over whatever `data` the table was given (see §4 for why).
- `onRowClick` — makes rows clickable; convention is **row click opens the Detail Drawer** (a "View" action), never Edit or Delete directly.
- Loading/error/empty states are built in (`Skeleton` rows, `ErrorState`, `EmptyState`) — never hand-roll these per page.

**Row actions are a single menu, not a row of icon buttons:**

```tsx
{
  header: '',
  className: 'text-right',
  accessor: (row) => (
    <RowActionsMenu
      actions={[
        { label: 'View', icon: Eye, onClick: () => setDetailItem(row) },
        { label: 'Edit', icon: Pencil, onClick: () => openEdit(row) },
        { label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingItem(row) },
      ]}
    />
  ),
}
```

`components/shared/RowActionsMenu.tsx` wraps the existing `DropdownMenu` primitive (already used by the header's user menu) behind a `⋮` trigger. It `stopPropagation()`s on both the trigger and the content so opening the menu or picking an item never also fires the row's `onRowClick`. Standard action set: **View, Edit, Delete** — in that order, Delete styled `variant="destructive"` (renders in red via the dropdown item's built-in destructive styling).

---

## 6. Drawer Layout

Two drawer types, two different shells — don't force one component to do both jobs.

### Detail Drawer (read-only) — `components/shared/DetailDrawerLayout.tsx`

```tsx
<DetailDrawerLayout
  open={open}
  onOpenChange={onOpenChange}
  title={item.item_name}
  subtitle={item.item_code}
  badge={<StatusBadge status={item.current_stock > 0 ? 'in_stock' : 'out_of_stock'} />}
  primaryAction={{ label: 'Edit Item', icon: Pencil, onClick: () => onEdit(item) }}
>
  <DetailSection>
    <DetailField label="Item Code" value={item.item_code} />
    ...
  </DetailSection>
  <Separator />
  <DetailSection title="Audit Information">
    <DetailField label="Created" value={formatDate(item.created_at)} />
    <DetailField label="Last Updated" value={formatDate(item.updated_at)} />
  </DetailSection>
</DetailDrawerLayout>
```

- **Header:** entity name (title) + a secondary identifier (subtitle — code/number/id) + a status badge.
- **Body:** one or more `DetailSection`s (optional heading + a 2-column grid of `DetailField`s), separated by `<Separator />` when there's more than one logical group. "Audit Information" (created/updated) is its own trailing section on every entity that has timestamps.
- **Footer:** `primaryAction` (usually "Edit") and an optional `secondaryAction`.
- **Status badge when the entity has no real status field:** don't fabricate one ("Active" that never changes is worse than no badge — it's actively misleading). Derive it from real data that stands in for status conceptually — Item uses `current_stock` (`in_stock`/`out_of_stock`, added to `StatusBadge`'s style map). When an entity has a genuine lifecycle (documents: draft/submitted/cancelled; settlements: unpaid/partially_paid/paid), use that directly — `StatusBadge` already supports both families of status out of the box.

### Form Drawer (create/edit) — no shared shell (yet)

Each entity keeps its own `<Entity>FormDrawer.tsx` using `Sheet`/`Form`/`react-hook-form`/`zod` directly (see `ItemFormDrawer.tsx`). One component handles both create and edit via `isEdit = !!<entity>`. Not extracted into a shared layout this round because form bodies vary more than detail bodies do (different field types, cross-field validation, dependent selects); revisit if a third or fourth form drawer turns out identical in shape.

**Numeric fields:** keep the RHF value as a `string`, validate with `.refine()`, convert to `Number(...)` only inside the mutation's `mutationFn`. `z.coerce.number()` combined with `zodResolver` produces an input/output type mismatch that fails `tsc -b` under this project's strictness — the string-then-convert approach sidesteps it entirely and should be the default for every future numeric field.

---

## 7. Shared List-Page Logic — API Factory & Hook

Built in Phase 2B once the pattern had proven itself on 6 near-identical pages (Item + 5 more) — extracting earlier, on Item alone, would have been guessing at the shape.

### `createCrudApi<T, TPayload>(basePath)` — `shared/services/crudApi.ts`

Every master-data entity's API module is five lines:

```tsx
const supplierCrud = createCrudApi<Supplier, SupplierFormValues>('/suppliers')

export const fetchSuppliers = supplierCrud.fetchList
export const createSupplier = supplierCrud.create
export const updateSupplier = supplierCrud.update
export const deleteSupplier = supplierCrud.remove
```

`fetchList(page)`, `fetchOne(id)`, `create(payload)`, `update(id, payload)`, `remove(id)` — the exact shape every Laravel `apiResource` controller exposes, wrapped in the `{success,message,data,meta?}` envelope. Re-exporting as named functions (not the factory object itself) keeps call sites reading like `fetchSuppliers(page)`, not `supplierApi.fetchList(page)`.

### `fetchLookupList<T>(path)` — `shared/services/lookupApi.ts`

For populating dropdowns (Item Group, UOM, Branch, ...) — takes page 1 of a paginated list endpoint. One function, reused by every `lib/lookupsApi.ts`-style module. Same page-1-only ceiling as the rest of this doc's filtering discussion — fine at today's reference-data volumes, called out here so it isn't rediscovered as a mystery bug later.

### `useEntityListPage<T, F>(options)` — `shared/hooks/useEntityListPage.ts`

The actual weight of a list page — page/search/filter/sort state, the list `useQuery`, the delete `useMutation`, the three drawer/dialog open-states, and the client-side filter+sort `useMemo` — is identical across every entity. This hook is that shared shape:

```tsx
const list = useEntityListPage<Supplier, SupplierFilterValues>({
  queryKey: 'suppliers',
  fetchList: fetchSuppliers,
  deleteOne: deleteSupplier,
  applyFilters: applySupplierFilters,
  emptyFilters: emptySupplierFilters,
  sorters: { supplier_code: (s) => s.supplier_code, supplier_name: (s) => s.supplier_name },
  deletedMessage: 'Supplier deleted.',
})
```

It returns `page/setPage`, `search/setSearch`, `filters/setFilters`, `sort/handleSortChange`, `rows` (already filtered+sorted), `listQuery`, `formOpen/editingItem`, `detailItem`, `deletingItem`, `openCreate/openEdit/confirmDelete`. A `<Entity>ListPage.tsx` becomes almost entirely JSX — table columns, drawers, and the page-specific labels — with no hand-written state management.

**What deliberately stays out of the hook:** table columns (differ per entity), the form/detail drawers (differ per entity), and the filter bar's actual controls (differ per entity or don't exist at all — see §4). Pulling those in would trade a clear composition for a configuration object nobody can read. `ItemListPage.tsx` is the reference for how a page built on this hook looks end to end.

---

## 8. Reusable UI Patterns — Full Inventory

| Component | Path | Introduced | Purpose |
|---|---|---|---|
| `PageHeader` | `components/shared/PageHeader.tsx` | 2A.1 | Title, description, record count, actions slot |
| `ActionBar` | `components/shared/ActionBar.tsx` | 2A.1 | Toolbar buttons (secondary actions + one primary) |
| `SearchBox` | `components/shared/SearchBox.tsx` | Sprint 1 | Debounced search input |
| `FilterPanel` | `components/shared/FilterPanel.tsx` | Sprint 1 | Shell for entity-specific filter controls + Clear button |
| `DataTable` | `components/shared/DataTable.tsx` | Sprint 1, extended 2A | Table with loading/error/empty states, sortable headers, clickable rows |
| `RowActionsMenu` | `components/shared/RowActionsMenu.tsx` | 2A.1 | Per-row View/Edit/Delete dropdown |
| `Pagination` | `components/shared/Pagination.tsx` | Sprint 1 | Page controls from API `meta` |
| `DetailDrawerLayout` + `DetailField` + `DetailSection` | `components/shared/DetailDrawerLayout.tsx` | 2A.1 | Read-only Drawer shell |
| `ConfirmationDialog` / `DeleteDialog` | `components/shared/*.tsx` | Sprint 1 | Yes/no confirmation, destructive preset |
| `StatusBadge` | `components/shared/StatusBadge.tsx` | Sprint 1, extended 2A.1 | Color-coded status pill; covers document lifecycle, settlement status, and now stock-derived placeholders |
| `LoadingOverlay`, `EmptyState`, `ErrorState` | `components/shared/*.tsx` | Sprint 1 | Used internally by `DataTable`, also usable standalone |
| `SectionNav` | `components/shared/SectionNav.tsx` | 2B | Horizontal sub-nav between sibling pages within one sidebar section (real `NavLink`s, not client-only tabs) |
| `Switch` | `components/ui/switch.tsx` | 2B | Boolean toggle (e.g. `is_active`) in forms — added from the already-installed `radix-ui` package, no new dependency |
| `createCrudApi`, `fetchLookupList` | `shared/services/crudApi.ts`, `lookupApi.ts` | 2B | Generic REST CRUD + dropdown-lookup fetchers — see §7 |
| `useEntityListPage` | `shared/hooks/useEntityListPage.ts` | 2B | Generic list-page state/query/mutation hook — see §7 |

**Per-entity, not shared** (one of each per module, following the same shape): `<Entity>FormDrawer`, `<Entity>DetailDrawer` (thin wrapper over `DetailDrawerLayout`), `<Entity>FiltersBar` (when the entity has anything worth filtering), `lib/<entity>Filters.ts`, `api/<entity>Api.ts` (built on `createCrudApi`).

---

## 9. Checklist for the Next CRUD Page

1. `features/<domain>/types.ts` — entity type + `<Entity>FormValues`.
2. `api/<entity>Api.ts` — `createCrudApi<Entity, EntityFormValues>('/path')`, re-export the five functions by name (see §7).
3. `lib/<entity>Filters.ts` — `<Entity>FilterValues` type + `apply<Entity>Filters(items, search, filters)` + `empty<Entity>Filters`. If nothing's filterable beyond search, `<Entity>FilterValues = Record<string, never>` and the function ignores its third argument — still required, because `useEntityListPage` needs the shape (see §4).
4. `components/<Entity>FiltersBar.tsx` — controlled, wraps `FilterPanel`. Skip this file entirely if step 3 has no real filter fields.
5. `components/<Entity>FormDrawer.tsx` — `Sheet` + `react-hook-form` + `zod`, string-then-convert for numeric fields, `Switch` for booleans.
6. `components/<Entity>DetailDrawer.tsx` — thin wrapper over `DetailDrawerLayout`; pick a real-or-derived status badge, or omit `badge` entirely if the entity has nothing status-like worth showing (see §6).
7. `pages/<Entity>ListPage.tsx` — call `useEntityListPage()` for all state/query/mutation, then compose `PageHeader` + `ActionBar` (Export/Import disabled) + `SearchBox` (+ `<Entity>FiltersBar` if it exists) + `DataTable` (with `RowActionsMenu`) + `Pagination` + the three drawers/dialog. If the page belongs to a section with siblings (Master Data now has six), render `SectionNav` at the top with that section's nav list.
8. Route in `app/router.tsx`; add to the section's nav list (e.g. `features/master/navigation.ts`) if it's a sibling of existing pages, or `layouts/navigation.ts` if it's a new top-level sidebar section.
9. `npx tsc -b` and `npx oxlint` clean before calling it done; smoke-test create/edit/delete/detail/filter in the browser against the real backend.
