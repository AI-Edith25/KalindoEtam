import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import type { PageSize } from '@/shared/hooks/usePageSize'
import type { PaginationMeta } from '@/shared/types/api'

interface PaginationProps {
  meta: PaginationMeta
  onPageChange: (page: number) => void
  /**
   * Pass the page's current PageSize (from the same state driving its header PageSizeControl)
   * only to hide the page-number buttons in "All" mode — this bar never renders its own size
   * selector (see PageSizeControl's own docblock for why one control beats two kept in sync).
   */
  pageSize?: PageSize
}

const JUMP = 2

export function Pagination({ meta, onPageChange, pageSize }: PaginationProps) {
  const { current_page, last_page, total, per_page } = meta

  if (total === 0) {
    return null
  }

  const rangeStart = (current_page - 1) * per_page + 1
  const rangeEnd = Math.min(current_page * per_page, total)
  const isAll = pageSize === 'all'

  return (
    <div className="flex flex-wrap items-center justify-between gap-4 py-3">
      <p className="text-sm text-muted-foreground">
        Showing {rangeStart}–{rangeEnd} of {total}
      </p>
      {!isAll && (
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            disabled={current_page <= 1}
            onClick={() => onPageChange(1)}
          >
            First
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={current_page <= 1}
            onClick={() => onPageChange(Math.max(1, current_page - JUMP))}
          >
            <ChevronsLeft className="size-4" />
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={current_page <= 1}
            onClick={() => onPageChange(current_page - 1)}
          >
            <ChevronLeft className="size-4" />
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">
            Page {current_page} of {last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={current_page >= last_page}
            onClick={() => onPageChange(current_page + 1)}
          >
            Next
            <ChevronRight className="size-4" />
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={current_page >= last_page}
            onClick={() => onPageChange(Math.min(last_page, current_page + JUMP))}
          >
            <ChevronsRight className="size-4" />
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={current_page >= last_page}
            onClick={() => onPageChange(last_page)}
          >
            Last
          </Button>
        </div>
      )}
    </div>
  )
}
