import type { ReactNode } from 'react'

/**
 * Sticky first column (Item / Chart of Account / Description) with a fixed width. Without it the
 * column auto-grows to the longest item name and, being sticky, covers Unit Price/Tax/Amount while
 * scrolling. Width alone isn't enough in an auto-layout table (td max-width is ignored), so every
 * direct child is capped too — that clamps its min-content contribution, and truncate on
 * SearchableSelect's label / text children does the ellipsis.
 */
export const STICKY_FIRST_COL =
  'sticky left-0 z-10 bg-background overflow-hidden w-44 min-w-44 max-w-44 sm:w-64 sm:min-w-64 sm:max-w-64 *:max-w-40 sm:*:max-w-60'

/**
 * Wraps a line-item editor `<Table>` for narrow viewports: the table itself still
 * scrolls horizontally, but a right-edge shadow hints that there's more to scroll to.
 * Pair with `sticky left-0 z-10 bg-background` on the first column's header/cells so
 * the row stays identifiable (Item/Chart of Account) while scrolling to reach Qty etc.
 *
 * Doesn't add its own `overflow-x-auto`: `<Table>` already renders one (see
 * ui/table.tsx). A second nested scroll container here breaks `sticky left-0` —
 * it sticks to whichever container actually scrolls, so columns after Item slide
 * underneath it instead of staying laid out beside it.
 */
export function LineItemTableScroll({ children }: { children: ReactNode }) {
  return (
    <div className="relative overflow-hidden rounded-md border">
      {children}
      <div className="pointer-events-none absolute inset-y-0 right-0 w-6 bg-gradient-to-l from-background to-transparent" />
    </div>
  )
}
