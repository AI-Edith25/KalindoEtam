import { useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink, Loader2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { PageHeader } from '@/components/shared/PageHeader'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchAccountLedger } from '../api/generalLedgerApi'
import { GeneralLedgerFiltersBar } from '../components/GeneralLedgerFiltersBar'
import { emptyGeneralLedgerFilters } from '../lib/generalLedgerFilters'
import { resolveJournalReferenceLink } from '../lib/journalReferenceLink'
import type { GeneralLedgerFilterValues, LedgerLine } from '../types'

/** Account Drill-down — a paginated, deterministically-ordered read of one account's posted lines. No writes anywhere on this page. */
export function GeneralLedgerDetailPage() {
  const { accountId } = useParams<{ accountId: string }>()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const [page, setPage] = useState(1)
  // Trial Balance's row-click drill-down (docs/TRIAL_BALANCE_DESIGN.md §5) carries date_from/date_to
  // as query params so this page opens pre-scoped to the same reporting period; absent, behaves exactly as before.
  const [filters, setFilters] = useState<GeneralLedgerFilterValues>({
    ...emptyGeneralLedgerFilters,
    dateFrom: searchParams.get('date_from') ?? emptyGeneralLedgerFilters.dateFrom,
    dateTo: searchParams.get('date_to') ?? emptyGeneralLedgerFilters.dateTo,
  })

  const ledgerQuery = useQuery({
    queryKey: [
      'general-ledger-account',
      accountId,
      page,
      filters.status,
      filters.referenceType,
      filters.referenceNumber,
      filters.branchId,
      filters.dateFrom,
      filters.dateTo,
    ],
    queryFn: () =>
      fetchAccountLedger(accountId!, {
        page,
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.referenceType ? { reference_type: filters.referenceType } : {}),
        ...(filters.referenceNumber ? { reference_number: filters.referenceNumber } : {}),
        ...(filters.branchId ? { branch_id: filters.branchId } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    enabled: !!accountId,
    placeholderData: (previous) => previous,
  })

  const columns: DataTableColumn<LedgerLine>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.posting_date) },
    {
      header: 'Journal No',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/accounting/journal-entries/${row.journal_entry_id}`)
          }}
        >
          {row.journal_number ?? '—'}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
    {
      header: 'Reference',
      accessor: (row) => {
        if (!row.reference_label) return '—'
        const link = row.reference_id ? resolveJournalReferenceLink(row.reference_label, row.reference_id) : null
        const label = `${row.reference_label}: ${row.reference_document_number ?? '—'}`

        return link ? (
          <Button
            variant="link"
            className="h-auto p-0"
            onClick={(event) => {
              event.stopPropagation()
              navigate(link)
            }}
          >
            {label}
            <ExternalLink className="size-3.5" />
          </Button>
        ) : (
          label
        )
      },
    },
    { header: 'Description', accessor: (row) => row.description ?? '—' },
    { header: 'Debit', accessor: (row) => (Number(row.debit) > 0 ? formatCurrency(row.debit) : '—'), className: 'text-right' },
    { header: 'Credit', accessor: (row) => (Number(row.credit) > 0 ? formatCurrency(row.credit) : '—'), className: 'text-right' },
    { header: 'Running Balance', accessor: (row) => formatCurrency(row.running_balance), className: 'text-right font-medium' },
  ]

  if (ledgerQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const ledger = ledgerQuery.data?.data
  if (!ledger) return null

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title={`${ledger.account.code} — ${ledger.account.name}`} description="Account ledger — every posted line, in deterministic order." />

      <Card>
        <CardHeader>
          <CardTitle>Account Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Code" value={ledger.account.code} />
            <DetailField label="Name" value={ledger.account.name} />
            <DetailField label="Type" value={<span className="capitalize">{ledger.account.account_type}</span>} />
            <DetailField label="Status" value={ledger.account.is_active ? 'Active' : 'Inactive'} />
            <DetailField label="Opening Balance" value={formatCurrency(ledger.opening_balance)} />
            <DetailField label="Ending Balance" value={formatCurrency(ledger.ending_balance)} />
          </DetailSection>
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-3">
        <GeneralLedgerFiltersBar
          value={filters}
          onChange={(value) => {
            setFilters(value)
            setPage(1)
          }}
          variant="detail"
        />
      </div>

      <DataTable
        columns={columns}
        data={ledger.lines}
        rowKey={(row) => row.id}
        isLoading={ledgerQuery.isFetching && !ledgerQuery.data}
        isError={ledgerQuery.isError}
        onRetry={() => ledgerQuery.refetch()}
        emptyMessage="No transactions in this period."
      />

      {ledgerQuery.data?.meta && <Pagination meta={ledgerQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
