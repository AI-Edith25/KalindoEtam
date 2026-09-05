import type { ReactNode } from 'react'

/**
 * Wraps a line-item editor `<Table>` for narrow viewports: the table itself still
 * scrolls horizontally, but a right-edge shadow hints that there's more to scroll to.
 * Pair with `sticky left-0 z-10 bg-background` on the first column's header/cells so
 * the row stays identifiable (Item/Chart of Account) while scrolling to reach Qty etc.
 */
export function LineItemTableScroll({ children }: { children: ReactNode }) {
  return (
    <div className="relative">
      <div className="overflow-x-auto rounded-md border">{children}</div>
      <div className="pointer-events-none absolute inset-y-0 right-0 w-6 rounded-r-md bg-gradient-to-l from-background to-transparent" />
    </div>
  )
}
