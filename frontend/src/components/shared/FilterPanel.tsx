import type { ReactNode } from 'react'
import { SlidersHorizontal, X } from 'lucide-react'
import { Button } from '@/components/ui/button'

interface FilterPanelProps {
  children: ReactNode
  onClear?: () => void
  hasActiveFilters?: boolean
}

/**
 * Generic wrapper for a row of filter controls (selects, date pickers,
 * etc.) — each module supplies its own filter fields as children.
 */
export function FilterPanel({ children, onClear, hasActiveFilters }: FilterPanelProps) {
  return (
    <div className="flex flex-wrap items-end gap-3 rounded-md border bg-card p-3">
      <SlidersHorizontal className="mb-2 size-4 text-muted-foreground" />
      {children}
      {onClear && hasActiveFilters && (
        <Button type="button" variant="ghost" size="sm" onClick={onClear} className="mb-0.5">
          <X className="size-3.5" />
          Clear filters
        </Button>
      )}
    </div>
  )
}
