import { useEffect, useState } from 'react'
import { Checkbox } from '@/components/ui/checkbox'
import type { DataTableColumn } from '@/components/shared/DataTable'

interface UseRowSelectionOptions {
  /** Clears per-row selection (and "select all filtered") when this changes — pass the active filter/page signature so a new search starts unselected. */
  resetKey?: unknown
}

/**
 * Shared row-selection logic — extracted from the Set-based
 * selection InvoiceListPage.tsx and JournalEntryListPage.tsx each hand-
 * rolled independently. Adds "select all N filtered" (a flag, not an ID
 * list — bulk export/print send it as "no ids, use the active filter",
 * the same contract InvoiceRepository::searchAll() already established) on
 * top of the existing page-scoped "select all visible" behavior.
 */
export function useRowSelection<T extends { id: string }>(rows: T[], totalFiltered: number, options: UseRowSelectionOptions = {}) {
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [selectAllFiltered, setSelectAllFiltered] = useState(false)

  useEffect(() => {
    setSelectedIds(new Set())
    setSelectAllFiltered(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [options.resetKey])

  const allVisibleSelected = rows.length > 0 && rows.every((row) => selectedIds.has(row.id))

  const toggleAllVisible = () => {
    setSelectAllFiltered(false)
    setSelectedIds(allVisibleSelected ? new Set() : new Set(rows.map((row) => row.id)))
  }

  const toggleRow = (id: string) => {
    setSelectAllFiltered(false)
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const clear = () => {
    setSelectedIds(new Set())
    setSelectAllFiltered(false)
  }

  const selectionColumn: DataTableColumn<T> = {
    id: 'select',
    header: (
      <Checkbox
        checked={selectAllFiltered || allVisibleSelected}
        onCheckedChange={toggleAllVisible}
        onClick={(event) => event.stopPropagation()}
        aria-label="Select all"
      />
    ),
    accessor: (row) => (
      <Checkbox
        checked={selectAllFiltered || selectedIds.has(row.id)}
        onCheckedChange={() => toggleRow(row.id)}
        onClick={(event) => event.stopPropagation()}
        aria-label="Select row"
      />
    ),
  }

  const selectedCount = selectAllFiltered ? totalFiltered : selectedIds.size

  return {
    selectedIds,
    selectAllFiltered,
    setSelectAllFiltered,
    selectionColumn,
    selectedCount,
    hasSelection: selectedCount > 0,
    clear,
    /** null when "select all filtered" is active — callers pass filters instead of ids to the backend, same as an empty selection meaning "use the active filter". */
    selectedIdsForRequest: selectAllFiltered ? null : Array.from(selectedIds),
  }
}
