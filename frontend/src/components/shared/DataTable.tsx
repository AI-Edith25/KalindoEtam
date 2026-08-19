import type { ReactNode } from 'react'
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { EmptyState } from './EmptyState'
import { ErrorState } from './ErrorState'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

export interface DataTableColumn<T> {
  /** Usually a plain string; a ReactNode (e.g. a header "select all" checkbox) needs `id` set too, since `header` alone can no longer serve as the React key below. */
  header: ReactNode
  accessor: (row: T) => ReactNode
  className?: string
  /** Enables a clickable sort header for this column. Requires `sort`/`onSortChange` on DataTable. */
  sortKey?: string
  /** Stable React key when `header` isn't a plain string. Falls back to `header` itself (unchanged behavior) when omitted. */
  id?: string
}

export interface DataTableSort {
  key: string
  direction: 'asc' | 'desc'
}

interface DataTableProps<T> {
  columns: DataTableColumn<T>[]
  data: T[]
  rowKey: (row: T) => string
  isLoading?: boolean
  isError?: boolean
  onRetry?: () => void
  emptyMessage?: string
  /** Row becomes clickable (e.g. to open a detail view) when provided. */
  onRowClick?: (row: T) => void
  sort?: DataTableSort
  onSortChange?: (key: string) => void
}

/** String headers use themselves as the React key (unchanged behavior for every existing caller); a ReactNode header needs `column.id` or falls back to its column index. */
function columnKey<T>(column: DataTableColumn<T>, index: number): string {
  return column.id ?? (typeof column.header === 'string' ? column.header : `col-${index}`)
}

/**
 * Plain hand-rolled table (no TanStack Table — not in this project's
 * approved stack) reused across every module's list view.
 */
export function DataTable<T>({
  columns,
  data,
  rowKey,
  isLoading,
  isError,
  onRetry,
  emptyMessage,
  onRowClick,
  sort,
  onSortChange,
}: DataTableProps<T>) {
  if (isError) {
    return <ErrorState onRetry={onRetry} />
  }

  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            {columns.map((column, index) => (
              <TableHead key={columnKey(column, index)} className={column.className}>
                {column.sortKey && onSortChange ? (
                  <button
                    type="button"
                    onClick={() => onSortChange(column.sortKey!)}
                    className="inline-flex items-center gap-1 hover:text-foreground"
                  >
                    {column.header}
                    {sort?.key === column.sortKey ? (
                      sort.direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                      ) : (
                        <ArrowDown className="size-3.5" />
                      )
                    ) : (
                      <ArrowUpDown className="size-3.5 opacity-40" />
                    )}
                  </button>
                ) : (
                  column.header
                )}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {isLoading ? (
            Array.from({ length: 5 }).map((_, rowIndex) => (
              <TableRow key={rowIndex}>
                {columns.map((column, index) => (
                  <TableCell key={columnKey(column, index)}>
                    <Skeleton className="h-4 w-full" />
                  </TableCell>
                ))}
              </TableRow>
            ))
          ) : data.length === 0 ? (
            <TableRow>
              <TableCell colSpan={columns.length} className="p-0">
                <EmptyState message={emptyMessage} />
              </TableCell>
            </TableRow>
          ) : (
            data.map((row) => (
              <TableRow
                key={rowKey(row)}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                className={cn(onRowClick && 'cursor-pointer hover:bg-muted/50')}
              >
                {columns.map((column, index) => (
                  <TableCell key={columnKey(column, index)} className={column.className}>
                    {column.accessor(row)}
                  </TableCell>
                ))}
              </TableRow>
            ))
          )}
        </TableBody>
      </Table>
    </div>
  )
}
