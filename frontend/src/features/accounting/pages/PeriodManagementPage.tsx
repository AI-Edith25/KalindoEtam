import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CheckCircle2, Loader2, Lock, RotateCw, Unlock, XCircle } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { SectionNav } from '@/components/shared/SectionNav'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { ConfirmationDialog } from '@/components/shared/ConfirmationDialog'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatDate } from '@/lib/utils'
import { useCompaniesLookup } from '@/features/master/hooks/useLookups'
import { closePeriod, createFiscalYear, fetchAccountingPeriods, fetchFiscalYears, fetchPeriodValidation, reopenPeriod } from '../api/periodApi'
import { accountingSectionNav } from '../navigation'
import type { AccountingPeriod } from '../types'

const ALL = '__all__'

/**
 * A locking mechanism only — no create/edit/delete of Journal Entries or
 * any report calculation happens here. Closing/reopening a period never
 * posts a journal entry. See docs/PERIOD_CLOSING_DESIGN.md.
 */
export function PeriodManagementPage() {
  const queryClient = useQueryClient()

  const [selectedFiscalYearId, setSelectedFiscalYearId] = useState<string | null>(null)
  const [statusFilter, setStatusFilter] = useState(ALL)
  const [newFiscalYearOpen, setNewFiscalYearOpen] = useState(false)
  const [companyId, setCompanyId] = useState('')
  const [fiscalYearName, setFiscalYearName] = useState('')
  const [startDate, setStartDate] = useState('')
  const [closingPeriod, setClosingPeriod] = useState<AccountingPeriod | null>(null)
  const [reopeningPeriod, setReopeningPeriod] = useState<AccountingPeriod | null>(null)

  const companies = useCompaniesLookup()
  const fiscalYearsQuery = useQuery({ queryKey: ['fiscal-years'], queryFn: fetchFiscalYears })

  useEffect(() => {
    if (!selectedFiscalYearId && fiscalYearsQuery.data && fiscalYearsQuery.data.length > 0) {
      setSelectedFiscalYearId(fiscalYearsQuery.data[0].id)
    }
  }, [fiscalYearsQuery.data, selectedFiscalYearId])

  const periodsQuery = useQuery({
    queryKey: ['accounting-periods', selectedFiscalYearId, statusFilter],
    queryFn: () =>
      fetchAccountingPeriods({
        ...(selectedFiscalYearId ? { fiscal_year_id: selectedFiscalYearId } : {}),
        ...(statusFilter !== ALL ? { status: statusFilter } : {}),
      }),
    enabled: !!selectedFiscalYearId,
  })

  const validationQuery = useQuery({
    queryKey: ['period-validation', closingPeriod?.id],
    queryFn: () => fetchPeriodValidation(closingPeriod!.id),
    enabled: !!closingPeriod,
  })

  const createFiscalYearMutation = useMutation({
    mutationFn: createFiscalYear,
    onSuccess: (fiscalYear) => {
      queryClient.invalidateQueries({ queryKey: ['fiscal-years'] })
      setSelectedFiscalYearId(fiscalYear.id)
      setNewFiscalYearOpen(false)
      setCompanyId('')
      setFiscalYearName('')
      setStartDate('')
      toast.success(`${fiscalYear.name} created — 12 monthly periods generated, all Open.`)
    },
    onError: (error) => toastApiError(error),
  })

  const closeMutation = useMutation({
    mutationFn: () => closePeriod(closingPeriod!.id),
    // Reads the period's name off the mutation's own resolved response, not the closingPeriod
    // dialog-state variable — ConfirmationDialog-style auto-close (see reopenMutation below) can
    // clear that state before this callback runs, since mutate() resolves before the network call.
    onSuccess: (period) => {
      queryClient.invalidateQueries({ queryKey: ['accounting-periods'] })
      toast.success(`${period.name} closed.`)
      setClosingPeriod(null)
    },
    onError: (error) => toastApiError(error),
  })

  const reopenMutation = useMutation({
    mutationFn: () => reopenPeriod(reopeningPeriod!.id),
    onSuccess: (period) => {
      queryClient.invalidateQueries({ queryKey: ['accounting-periods'] })
      toast.success(`${period.name} reopened.`)
      setReopeningPeriod(null)
    },
    onError: (error) => toastApiError(error),
  })

  const columns: DataTableColumn<AccountingPeriod>[] = [
    { header: 'Accounting Period', accessor: (row) => row.name },
    { header: 'Fiscal Year', accessor: (row) => row.fiscal_year_name ?? '—', className: 'text-muted-foreground' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
    { header: 'Closed By', accessor: (row) => row.closed_by ?? '—', className: 'text-muted-foreground' },
    { header: 'Closed At', accessor: (row) => formatDate(row.closed_at), className: 'text-muted-foreground' },
    { header: 'Reopened By', accessor: (row) => row.reopened_by ?? '—', className: 'text-muted-foreground' },
    { header: 'Reopened At', accessor: (row) => formatDate(row.reopened_at), className: 'text-muted-foreground' },
    {
      header: 'Actions',
      accessor: (row) =>
        row.status === 'open' ? (
          <Button size="sm" variant="outline" onClick={() => setClosingPeriod(row)}>
            <Lock className="size-3.5" />
            Close
          </Button>
        ) : (
          <Button size="sm" variant="outline" onClick={() => setReopeningPeriod(row)}>
            <Unlock className="size-3.5" />
            Reopen
          </Button>
        ),
    },
  ]

  const allChecksPassed = validationQuery.data?.every((check) => check.passed) ?? false

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={accountingSectionNav} />

      <PageHeader
        title="Period Closing"
        description="Manage fiscal years and accounting periods — closing a period locks its posting date range against new or edited Journal Entries; reports themselves are never affected."
        actions={
          <ActionBar
            actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => periodsQuery.refetch(), disabled: periodsQuery.isFetching }]}
            primary={{ label: 'New Fiscal Year', onClick: () => setNewFiscalYearOpen(true) }}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Fiscal Year</span>
          <Select value={selectedFiscalYearId ?? ''} onValueChange={(value) => setSelectedFiscalYearId(value)}>
            <SelectTrigger className="w-48">
              <SelectValue placeholder={fiscalYearsQuery.isLoading ? 'Loading…' : 'Select a fiscal year'} />
            </SelectTrigger>
            <SelectContent>
              {fiscalYearsQuery.data?.map((fiscalYear) => (
                <SelectItem key={fiscalYear.id} value={fiscalYear.id}>
                  {fiscalYear.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Status</span>
          <Select value={statusFilter} onValueChange={(value) => setStatusFilter(value)}>
            <SelectTrigger className="w-36">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All statuses</SelectItem>
              <SelectItem value="open">Open</SelectItem>
              <SelectItem value="closed">Closed</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <DataTable
        columns={columns}
        data={periodsQuery.data ?? []}
        rowKey={(row) => row.id}
        isLoading={periodsQuery.isLoading}
        isError={periodsQuery.isError}
        onRetry={() => periodsQuery.refetch()}
        emptyMessage={fiscalYearsQuery.data?.length === 0 ? 'No fiscal years yet — create one to get started.' : 'No accounting periods match these filters.'}
      />

      <Dialog open={newFiscalYearOpen} onOpenChange={setNewFiscalYearOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>New Fiscal Year</DialogTitle>
            <DialogDescription>Generates 12 monthly accounting periods, all Open, starting from the date below.</DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <Label>Company</Label>
              <Select value={companyId} onValueChange={setCompanyId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a company" />
                </SelectTrigger>
                <SelectContent>
                  {companies.data?.map((company) => (
                    <SelectItem key={company.id} value={company.id}>
                      {company.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Name</Label>
              <Input value={fiscalYearName} onChange={(event) => setFiscalYearName(event.target.value)} placeholder="e.g. FY2027" />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Start Date</Label>
              <Input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setNewFiscalYearOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={!companyId || !fiscalYearName || !startDate || createFiscalYearMutation.isPending}
              onClick={() => createFiscalYearMutation.mutate({ company_id: companyId, name: fiscalYearName, start_date: startDate })}
            >
              {createFiscalYearMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Create
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Close is gated behind the §3 validation checklist — never just a bare confirmation. */}
      <Dialog open={!!closingPeriod} onOpenChange={(open) => !open && setClosingPeriod(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Close {closingPeriod?.name}</DialogTitle>
            <DialogDescription>Every check below must pass before this period can be closed.</DialogDescription>
          </DialogHeader>
          {validationQuery.isLoading ? (
            <div className="flex items-center justify-center py-6">
              <Loader2 className="size-5 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <ul className="flex flex-col gap-2">
              {validationQuery.data?.map((check) => (
                <li key={check.key} className="flex items-start gap-2 text-sm">
                  {check.passed ? (
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-green-600" />
                  ) : (
                    <XCircle className="mt-0.5 size-4 shrink-0 text-red-600" />
                  )}
                  <span>
                    {check.label}
                    {check.detail && <span className="text-muted-foreground"> — {check.detail}</span>}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setClosingPeriod(null)} disabled={closeMutation.isPending}>
              Cancel
            </Button>
            <Button onClick={() => closeMutation.mutate()} disabled={!allChecksPassed || closeMutation.isPending}>
              {closeMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Close Period
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmationDialog
        open={!!reopeningPeriod}
        onOpenChange={(open) => !open && setReopeningPeriod(null)}
        title={`Reopen ${reopeningPeriod?.name ?? ''}?`}
        description="Only an administrator can reopen a closed period. This allows new and edited Journal Entries to post into this date range again."
        confirmLabel="Reopen"
        variant="destructive"
        onConfirm={() => reopenMutation.mutate()}
      />
    </div>
  )
}
