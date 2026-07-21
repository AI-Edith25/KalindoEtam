import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, RotateCw } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { formatCurrency } from '@/lib/utils'
import { fetchCompaniesLookup } from '@/features/master/api/lookupsApi'
import { fetchCashFlow } from '../api/cashFlowApi'
import { CashFlowFiltersBar } from '../components/CashFlowFiltersBar'
import { emptyCashFlowFilters } from '../lib/cashFlowFilters'
import { resolvePeriodPreset } from '../lib/profitLossFilters'
import { accountingSectionNav } from '../navigation'
import type { CashFlowActivitySection, CashFlowFilterValues } from '../types'

/** A read model, like General Ledger/Trial Balance/Profit & Loss/Balance Sheet — no create/edit/delete anywhere on this page. See docs/CASH_FLOW_DESIGN.md §1. */
export function CashFlowListPage() {
  const navigate = useNavigate()
  const [filters, setFilters] = useState<CashFlowFilterValues>(emptyCashFlowFilters)

  const companies = useQuery({ queryKey: ['companies-lookup'], queryFn: fetchCompaniesLookup })
  const { dateFrom, dateTo } = resolvePeriodPreset(filters, companies.data ?? [])

  const reportQuery = useQuery({
    queryKey: ['cash-flow', dateFrom, dateTo, filters.branchId, filters.companyId],
    queryFn: () =>
      fetchCashFlow({
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

  // Net Profit isn't one account — drills into the existing Profit & Loss report itself, using
  // the identical date range (no fiscal-year re-anchoring needed, unlike Balance Sheet's own
  // Current Year Profit — Cash Flow's own filter already IS the period). See §9.
  const goToProfitLoss = () => {
    const params = new URLSearchParams({ ...(dateFrom ? { date_from: dateFrom } : {}), ...(dateTo ? { date_to: dateTo } : {}) })
    navigate(`/accounting/profit-loss?${params.toString()}`)
  }

  const renderDerivedRow = (label: string, value: number | string, emphasis: 'normal' | 'strong' = 'normal', onClick?: () => void) => (
    <TableRow className={[emphasis === 'strong' ? 'bg-muted/50 border-y-2' : 'border-y', onClick ? 'cursor-pointer' : ''].join(' ')} onClick={onClick}>
      <TableCell className={emphasis === 'strong' ? 'text-base font-bold' : 'font-semibold'}>{label}</TableCell>
      <TableCell className={emphasis === 'strong' ? 'text-right text-base font-bold' : 'text-right font-semibold'}>
        {formatCurrency(value)}
      </TableCell>
    </TableRow>
  )

  const renderActivitySection = (section: CashFlowActivitySection, derivedLabel: string) => (
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
      {renderDerivedRow(derivedLabel, section.net_cash)}
    </>
  )

  const report = reportQuery.data

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={accountingSectionNav} />

      <PageHeader
        title="Cash Flow"
        description="A read-only Indirect Method Cash Flow Statement for a reporting period — Net Profit from Profit & Loss, adjusted for changes in Balance Sheet accounts, never a second calculation."
        actions={<ActionBar actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => reportQuery.refetch(), disabled: reportQuery.isFetching }]} />}
      />

      <div className="flex flex-wrap items-center gap-3">
        <CashFlowFiltersBar value={filters} onChange={setFilters} />
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
                <TableRow className="bg-muted/30">
                  <TableCell colSpan={2} className="font-semibold">
                    {report.operating.label}
                  </TableCell>
                </TableRow>
                <TableRow className="cursor-pointer" onClick={goToProfitLoss}>
                  <TableCell className="pl-6">Net Profit for the Period</TableCell>
                  <TableCell className="text-right">{formatCurrency(report.net_profit)}</TableCell>
                </TableRow>
                {report.operating.lines.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={2} className="text-muted-foreground italic">
                      No working-capital changes this period.
                    </TableCell>
                  </TableRow>
                ) : (
                  report.operating.lines.map((line) => (
                    <TableRow key={line.id} className="cursor-pointer" onClick={() => goToLedger(line.id)}>
                      <TableCell className="pl-6">{line.name}</TableCell>
                      <TableCell className="text-right">{formatCurrency(line.amount)}</TableCell>
                    </TableRow>
                  ))
                )}
                {renderDerivedRow('Net Cash from Operating Activities', report.operating.net_cash)}

                {renderActivitySection(report.investing, 'Net Cash from Investing Activities')}
                {renderActivitySection(report.financing, 'Net Cash from Financing Activities')}

                {renderDerivedRow('Net Increase (Decrease) in Cash', report.net_cash_movement, 'strong')}
                {renderDerivedRow('Opening Cash Balance', report.opening_cash)}
                {renderDerivedRow('Closing Cash Balance', report.closing_cash, 'strong')}
              </TableBody>
            </Table>
          </div>

          <Card>
            <CardContent className="flex flex-wrap items-center justify-end gap-6 py-4">
              <Badge
                className={
                  report.is_balanced
                    ? 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300'
                    : 'bg-red-100 text-red-700 border-transparent dark:bg-red-950 dark:text-red-300'
                }
              >
                {report.is_balanced ? 'Balanced' : 'Imbalance detected'}
              </Badge>
              <div className="flex items-center gap-2 text-base">
                <span className="text-muted-foreground">Opening Cash</span>
                <span className="font-semibold">{formatCurrency(report.opening_cash)}</span>
              </div>
              <div className="flex items-center gap-2 text-base">
                <span className="text-muted-foreground">Closing Cash</span>
                <span className="font-semibold">{formatCurrency(report.closing_cash)}</span>
              </div>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
