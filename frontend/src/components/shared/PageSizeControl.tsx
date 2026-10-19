import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { PAGE_SIZE_OPTIONS, type PageSize } from '@/shared/hooks/usePageSize'
import { formatNumber } from '@/lib/utils'
import type { PaginationMeta } from '@/shared/types/api'

interface PageSizeControlProps {
  /** Omit while the list is still loading — the "Showing X–Y of Z" text is skipped until there's a real count. */
  meta?: PaginationMeta
  pageSize: PageSize
  onPageSizeChange: (size: PageSize) => void
}

/**
 * The primary "Show N entries" control — lives in PageHeader's actions row (top of the
 * page, next to the title/count), not buried below the table, so changing it never requires
 * scrolling first. Pagination's own bottom bar deliberately does NOT duplicate this dropdown:
 * a single control means a single state and no scroll-position-preservation problem to solve.
 */
export function PageSizeControl({ meta, pageSize, onPageSizeChange }: PageSizeControlProps) {
  const rangeStart = meta && meta.total > 0 ? (meta.current_page - 1) * meta.per_page + 1 : 0
  const rangeEnd = meta ? Math.min(meta.current_page * meta.per_page, meta.total) : 0

  return (
    <div className="flex items-center gap-3">
      <div className="flex items-center gap-1.5">
        <span className="text-sm text-muted-foreground">Show</span>
        <Select value={String(pageSize)} onValueChange={(next) => onPageSizeChange(next === 'all' ? 'all' : (Number(next) as PageSize))}>
          <SelectTrigger className="w-20">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PAGE_SIZE_OPTIONS.map((option) => (
              <SelectItem key={option} value={String(option)}>
                {option === 'all' ? 'All' : option}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <span className="text-sm text-muted-foreground">entries</span>
      </div>
      {meta && meta.total > 0 && (
        <span className="whitespace-nowrap text-sm text-muted-foreground">
          Showing {formatNumber(rangeStart)}–{formatNumber(rangeEnd)} of {formatNumber(meta.total)}
        </span>
      )}
    </div>
  )
}
