import { useState } from 'react'
import { RotateCw } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { Badge } from '@/components/ui/badge'
import { formatNumber } from '@/lib/utils'
import { fetchAuditLogsPaged } from '../api/auditLogApi'
import { AuditLogFiltersBar } from '../components/AuditLogFiltersBar'
import { administrationNav } from '../navigation'
import type { AuditLog, AuditLogFilterValues } from '../types'

/** Audit entries need time-of-day, not just date — formatDate() (lib/utils) deliberately only formats a date, so this stays local rather than changing that shared helper for one page. */
function formatTimestamp(value: string): string {
  return new Date(value).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/** Read-only, system-wide — see docs/ADMINISTRATION_DESIGN.md §6. No ActionBar primary action, no row actions: an entry is only ever written by AuditLogService::record(), never by a user directly. */
export function AuditLogListPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<AuditLogFilterValues>({})

  const listQuery = useQuery({
    queryKey: ['audit-logs-paged', page, filters, search],
    queryFn: () => fetchAuditLogsPaged(page, { ...filters, search: search || undefined }),
    placeholderData: (previous) => previous,
  })

  const columns: DataTableColumn<AuditLog>[] = [
    { header: 'User', accessor: (row) => row.user?.email ?? 'System' },
    { header: 'Action', accessor: (row) => <Badge variant="secondary">{row.action}</Badge> },
    { header: 'Module', accessor: (row) => row.module },
    { header: 'Description', accessor: (row) => row.description ?? '—' },
    { header: 'IP Address', accessor: (row) => row.ip_address ?? '—' },
    { header: 'Timestamp', accessor: (row) => formatTimestamp(row.created_at) },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={administrationNav} />

      <PageHeader
        title="Audit Log"
        description="A system-wide, read-only record of who did what, when, and from where."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} entries` : undefined}
        actions={<ActionBar actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching }]} />}
      />

      <div className="flex flex-col gap-3">
        <SearchBox value={search} onChange={setSearch} placeholder="Search description…" />
        <AuditLogFiltersBar value={filters} onChange={setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={listQuery.data?.data ?? []}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage="No audit entries yet."
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
