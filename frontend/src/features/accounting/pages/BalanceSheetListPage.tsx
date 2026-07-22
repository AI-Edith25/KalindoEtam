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
import { useCompaniesLookup } from '@/features/master/hooks/useLookups'
import { fetchBalanceSheet } from '../api/balanceSheetApi'
import { BalanceSheetFiltersBar } from '../components/BalanceSheetFiltersBar'
import { emptyBalanceSheetFilters } from '../lib/balanceSheetFilters'
import { resolveFiscalYearStart, toDateString } from '../lib/profitLossFilters'
import type { BalanceSheetFilterValues, BalanceSheetSectionData } from '../types'

/** A read model, like General Ledger/Trial Balance/Profit & Loss — no create/edit/delete anywhere on this page. See docs/BALANCE_SHEET_DESIGN.md §1. */
export function BalanceSheetListPage() {
  const navigate = useNavigate()
  const [filters, setFilters] = useState<BalanceSheetFilterValues>(emptyBalanceSheetFilters)

  const companies = useCompaniesLookup()

  const reportQuery = useQuery({
    queryKey: ['balance-sheet', filters.asOfDate, filters.branchId, filters.companyId],
    queryFn: () =>
      fetchBalanceSheet({
        as_of_date: filters.asOfDate,
        ...(filters.branchId ? { branch_id: filters.branchId } : {}),
        ...(filters.companyId ? { company_id: filters.companyId } : {}),
      }),
    enabled: !!filters.asOfDate,
    placeholderData: (previous) => previous,
  })

  const goToLedger = (accountId: string) => {
    const params = new URLSearchParams({ date_to: filters.asOfDate })
    navigate(`/accounting/general-ledger/${accountId}?${params.toString()}`)
  }

  // Current Year Profit isn't one account — drills into the existing Profit & Loss report itself,
  // pre-scoped to the same fiscal year, rather than a General Ledger account. See §9.
  const goToProfitLoss = () => {
    const fiscalYearStart = toDateString(resolveFiscalYearStart(companies.data ?? [], filters.companyId))
    const params = new URLSearchParams({ date_from: fiscalYearStart, date_to: filters.asOfDate })
    navigate(`/accounting/profit-loss?${params.toString()}`)
  }

  const renderSection = (section: BalanceSheetSectionData | undefined) => {
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
              No balance in this section.
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
          <TableCell className="font-medium">{section.label}</TableCell>
          <TableCell className="text-right font-medium">{formatCurrency(section.subtotal)}</TableCell>
        </TableRow>
      </>
    )
  }

  const renderDerivedRow = (label: string, value: number | string, emphasis: 'normal' | 'strong' = 'normal', onClick?: () => void) => (
    <TableRow className={[emphasis === 'strong' ? 'bg-muted/50 border-y-2' : 'border-y', onClick ? 'cursor-pointer' : ''].join(' ')} onClick={onClick}>
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
        title="Balance Sheet"
        description="A read-only snapshot of Assets, Liabilities, and Equity as of a chosen date — derived entirely from posted Journal Entries plus Profit & Loss's own Current Year Profit, never a second calculation."
        actions={<ActionBar actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => reportQuery.refetch(), disabled: reportQuery.isFetching }]} />}
      />

      <div className="flex flex-wrap items-center gap-3">
        <BalanceSheetFiltersBar value={filters} onChange={setFilters} />
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
                {renderSection(getSection('current_asset'))}
                {renderSection(getSection('non_current_asset'))}
                {renderDerivedRow('Total Assets', report.total_assets, 'strong')}

                {renderSection(getSection('current_liability'))}
                {renderSection(getSection('long_term_liability'))}
                {renderDerivedRow('Total Liabilities', report.total_liabilities)}

                {renderSection(getSection('share_capital'))}
                {renderSection(getSection('retained_earnings'))}
                {renderDerivedRow('Current Year Profit', report.current_year_profit, 'normal', goToProfitLoss)}
                {renderDerivedRow('Total Equity', report.total_equity)}

                {renderDerivedRow('Total Liabilities and Equity', report.total_liabilities_and_equity, 'strong')}
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
                {report.is_balanced
                  ? 'Balanced'
                  : `Imbalance detected (${formatCurrency(Math.abs(Number(report.total_assets) - Number(report.total_liabilities_and_equity)))})`}
              </Badge>
              <div className="flex items-center gap-2 text-base">
                <span className="text-muted-foreground">Total Assets</span>
                <span className="font-semibold">{formatCurrency(report.total_assets)}</span>
              </div>
              <div className="flex items-center gap-2 text-base">
                <span className="text-muted-foreground">Total Liabilities and Equity</span>
                <span className="font-semibold">{formatCurrency(report.total_liabilities_and_equity)}</span>
              </div>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
