import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/shared/services/errorHandler'
import type { ApiListResponse } from '@/shared/types/api'
import type { DataTableSort } from '@/components/shared/DataTable'

interface UseEntityListPageOptions<T, F> {
  /** TanStack Query key prefix — paired with `page` for the list query, and used to invalidate after mutations. */
  queryKey: string
  fetchList: (page: number) => Promise<ApiListResponse<T>>
  deleteOne: (id: string) => Promise<void>
  applyFilters: (items: T[], search: string, filters: F) => T[]
  emptyFilters: F
  sorters?: Record<string, (item: T) => string | number>
  deletedMessage?: string
}

/**
 * Every master-data list page (Item, Supplier, Customer, Warehouse,
 * ItemGroup, Uom) needs the same page/search/filter/sort state, the
 * same list query + delete mutation, the same three drawer/dialog open
 * states, and the same client-side filter+sort memo. This hook is that
 * shared shape — see docs/ERP_DESIGN_SYSTEM.md §4 for why filtering is
 * client-side, and §8 for how a new entity plugs into this.
 *
 * What stays page-specific: table columns, the form/detail drawers, and
 * the filters bar's actual controls — all of those differ enough per
 * entity that forcing them into this hook would do more harm than good.
 */
export function useEntityListPage<T extends { id: string }, F>({
  queryKey,
  fetchList,
  deleteOne,
  applyFilters,
  emptyFilters,
  sorters = {},
  deletedMessage = 'Deleted.',
}: UseEntityListPageOptions<T, F>) {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<F>(emptyFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [formOpen, setFormOpen] = useState(false)
  const [editingItem, setEditingItem] = useState<T | null>(null)
  const [detailItem, setDetailItem] = useState<T | null>(null)
  const [deletingItem, setDeletingItem] = useState<T | null>(null)

  const queryClient = useQueryClient()

  const listQuery = useQuery({
    queryKey: [queryKey, page],
    queryFn: () => fetchList(page),
    placeholderData: (previous) => previous,
  })

  // A delete (or any change that shrinks the total) can leave `page` past
  // the new last page — e.g. deleting the only row on page 2 of 2. Without
  // this, the user is stranded on a dead "No accounts yet" page instead of
  // landing back on the last real one.
  useEffect(() => {
    const meta = listQuery.data?.meta
    if (meta && meta.last_page > 0 && page > meta.last_page) {
      setPage(meta.last_page)
    }
  }, [listQuery.data, page])

  const deleteMutation = useMutation({
    mutationFn: (item: T) => deleteOne(item.id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [queryKey] })
      toast.success(deletedMessage)
      setDeletingItem(null)
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error)),
  })

  const rows = useMemo(() => {
    const filtered = applyFilters(listQuery.data?.data ?? [], search, filters)

    if (!sort) return filtered

    const getter = sorters[sort.key]
    if (!getter) return filtered

    return [...filtered].sort((a, b) => {
      const av = getter(a)
      const bv = getter(b)
      const cmp = typeof av === 'number' && typeof bv === 'number' ? av - bv : String(av).localeCompare(String(bv))
      return sort.direction === 'asc' ? cmp : -cmp
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listQuery.data, search, filters, sort])

  const handleSortChange = (key: string) => {
    setSort((prev) => (prev?.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'asc' }))
  }

  const openCreate = () => {
    setEditingItem(null)
    setFormOpen(true)
  }

  const openEdit = (item: T) => {
    setDetailItem(null)
    setFormOpen(true)
    setEditingItem(item)
  }

  const confirmDelete = () => {
    if (deletingItem) deleteMutation.mutate(deletingItem)
  }

  return {
    page,
    setPage,
    search,
    setSearch,
    filters,
    setFilters,
    sort,
    handleSortChange,
    rows,
    listQuery,
    formOpen,
    setFormOpen,
    editingItem,
    detailItem,
    setDetailItem,
    deletingItem,
    setDeletingItem,
    deleteMutation,
    openCreate,
    openEdit,
    confirmDelete,
  }
}
