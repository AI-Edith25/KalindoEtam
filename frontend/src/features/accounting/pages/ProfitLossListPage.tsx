import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, RotateCw } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { formatCurrency } from '@/lib/utils'
import { useCompaniesLookup } from '@/features/master/hooks/useLookups'
import { fetchProfitLoss } from '../api/profitLossApi'
import { ProfitLossFiltersBar } from '../components/ProfitLossFiltersBar'
import { emptyProfitLossFilters, resolvePeriodPreset } from '../lib/profitLossFilters'
import type { ProfitLossFilterValues, ProfitLossSectionData } from '../types'

/** A read model, like General Ledger and Trial Balance — no create/edit/delete anywhere on this page. See docs/PROFIT_LOSS_DESIGN.md §1. */
export function ProfitLossListPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  // Balance Sheet's Current Year Profit drill-down (docs/BALANCE_SHEET_DESIGN.md §9) carries
  // date_from/date_to as query params, the same "opens pre-scoped, absent behaves exactly as
  // before" pattern already used for General Ledger's own Account Detail page.
  const [filters, setFilters] = useState<ProfitLossFilterValues>(() => {
    const dateFrom = searchParams.get('date_from')
    if (!dateFrom) return emptyProfitLossFilters
    return { ...emptyProfitLossFilters, periodPreset: 'custom', dateFrom, dateTo: searchParams.get('date_to') ?? '' }
  })

  const companies = useCompaniesLookup()
  const { dateFrom, dateTo } = resolvePeriodPreset(filters, companies.data ?? [])

  const reportQuery = useQuery({
    queryKey: ['profit-loss', dateFrom, dateTo, filters.branchId, filters.companyId],
    queryFn: () =>
      fetchProfitLoss({
        date_from: dateFrom,
        ...(dateTo ? { date_to: dateTo } : {}),
        ...(filters.branchId ? { branch_id: filters.branchId } : {}),
        ...(filters.companyId ? { company_id: filters.companyId } : {}),
      }),
    enabled: !!dateFrom,
    placeholderData: (previous) => previous,
  })

  const goToLedger = (accountId: string) => {
    const params = new URLSearchParams({ ...(dateFrom ? { date_from: dateFrom } : {}), ...(dateTo ? { date_to: dateTo } : {}) })
    navigate(`/accounting/general-ledger/${accountId}?${params.toString()}`)
  }

  const renderSection = (section: ProfitLossSectionData | undefined, prefix?: string) => {
    if (!section) return null

    return (
      <>
        <TableRow className="bg-muted/30">
          <TableCell colSpan={2} className="font-semibold">
            {section.label}
          </TableCell>
        </TableRow>
        {section.lines.length === 0 ? (
          <TableRow>
            <TableCell colSpan={2} className="text-muted-foreground italic">
              No activity this period.
            </TableCell>
          </TableRow>
        ) : (
          section.lines.map((line) => (
            <TableRow key={line.id} className="cursor-pointer" onClick={() => goToLedger(line.id)}>
              <TableCell className="pl-6">{line.name}</TableCell>
              <TableCell className="text-right">{formatCurrency(line.amount)}</TableCell>
            </TableRow>
          ))
        )}
        <TableRow>
          <TableCell className="font-medium">
            {prefix ?? ''} {section.label}
          </TableCell>
          <TableCell className="text-right font-medium">{formatCurrency(section.subtotal)}</TableCell>
        </TableRow>
      </>
    )
  }

  const renderDerivedRow = (label: string, value: number | string, emphasis: 'normal' | 'strong' = 'normal') => (
    <TableRow className={emphasis === 'strong' ? 'bg-muted/50 border-y-2' : 'border-y'}>
      <TableCell className={emphasis === 'strong' ? 'text-base font-bold' : 'font-semibold'}>{label}</TableCell>
      <TableCell className={emphasis === 'strong' ? 'text-right text-base font-bold' : 'text-right font-semibold'}>
        {formatCurrency(value)}
      </TableCell>
    </TableRow>
  )

  const report = reportQuery.data
  const getSection = (key: string) => report?.sections.find((section) => section.key === key)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="accounting" />

      <PageHeader
        title="Profit & Loss"
        description="A read-only report of Revenue, Cost of Goods Sold, Operating Expenses, and Net Profit for a reporting period — derived entirely from posted Journal Entries, never a second calculation."
        actions={<ActionBar actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => reportQuery.refetch(), disabled: reportQuery.isFetching }]} />}
      />

      <div className="flex flex-wrap items-center gap-3">
        <ProfitLossFiltersBar value={filters} onChange={setFilters} />
      </div>

      {reportQuery.isLoading ? (
        <div className="flex min-h-64 items-center justify-center">
          <Loader2 className="size-6 animate-spin text-muted-foreground" />
        </div>
      ) : !report ? null : (
        <>
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableBody>
                {renderSection(getSection('revenue'))}
                {renderSection(getSection('cost_of_goods_sold'), 'Less:')}
                {renderDerivedRow('Gross Profit', report.gross_profit)}
                {renderSection(getSection('operating_expense'), 'Less:')}
                {renderDerivedRow('Operating Income', report.operating_income)}
                {renderSection(getSection('other_income'))}
                {renderSection(getSection('other_expense'), 'Less:')}
                {renderDerivedRow('Net Profit Before Tax', report.net_profit_before_tax)}
                <TableRow>
                  <TableCell className="font-medium">Tax</TableCell>
                  <TableCell className="text-right font-medium text-muted-foreground">
                    {report.tax === null ? 'Not configured' : formatCurrency(report.tax)}
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>

          <Card>
            <CardContent className="flex items-center justify-end gap-3 py-4">
              <span className="text-lg text-muted-foreground">Net Profit</span>
              <span className="text-lg font-bold">{formatCurrency(report.net_profit)}</span>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
