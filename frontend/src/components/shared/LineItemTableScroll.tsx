import type { ReactNode } from 'react'

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
