import { useQuery } from '@tanstack/react-query'
import { TrendingDown, TrendingUp } from 'lucide-react'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { SummaryCard } from '../components/SummaryCard'
import { LowStockCard } from '../components/LowStockCard'
import { RecentTransactionsCard } from '../components/RecentTransactionsCard'
import { PendingTasksCard } from '../components/PendingTasksCard'
import { FinancialSummaryCards } from '../components/FinancialSummaryCards'
import { RevenueExpenseChart } from '../components/RevenueExpenseChart'
import { fetchAccountsPayableOutstanding, fetchAccountsReceivableOutstanding } from '../api/dashboardApi'
import { formatCurrency, formatNumber } from '@/lib/utils'

/**
 * Operational landing page, not an analytics page — trend/summary charts
 * live in their own reporting modules. Each widget is shown only when the
 * current user holds the existing {module}.{action} permission it depends
 * on, reusing the exact permission architecture Sprint 22B shipped. See
 * docs/DASHBOARD_DESIGN.md §1/§4.
 */
export function DashboardPage() {
  const canViewInventory = useHasPermission('master.items.view')
  const canViewFinancials = useHasPermission('accounting.journal_entries.view')
  const canViewPayable = useHasPermission('finance.accounts_payable.view')
  const canViewReceivable = useHasPermission('finance.accounts_receivable.view')
  const canViewDashboard = useHasPermission('dashboard.view')

  const apOutstanding = useQuery({
    queryKey: ['dashboard', 'ap-outstanding'],
    queryFn: fetchAccountsPayableOutstanding,
    enabled: canViewPayable,
  })

  const arOutstanding = useQuery({
    queryKey: ['dashboard', 'ar-outstanding'],
    queryFn: fetchAccountsReceivableOutstanding,
    enabled: canViewReceivable,
  })

  const hasAnyKpi = canViewPayable || canViewReceivable || canViewFinancials

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Dashboard</h1>
        <p className="text-sm text-muted-foreground">Today's outstanding balances, low stock, and pending work.</p>
      </div>

      {hasAnyKpi && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {canViewPayable && (
            <SummaryCard
              title="Outstanding Payable"
              value={formatCurrency(apOutstanding.data?.total_outstanding ?? 0)}
              description={`${formatNumber(apOutstanding.data?.count ?? 0)} unpaid/partial invoices`}
              icon={TrendingDown}
              isLoading={apOutstanding.isLoading}
              tone="warning"
              to="/finance/outgoing"
            />
          )}
          {canViewReceivable && (
            <SummaryCard
              title="Outstanding Receivable"
              value={formatCurrency(arOutstanding.data?.total_outstanding ?? 0)}
              description={`${formatNumber(arOutstanding.data?.count ?? 0)} unpaid/partial invoices`}
              icon={TrendingUp}
              isLoading={arOutstanding.isLoading}
              tone="warning"
              to="/finance/incoming"
            />
          )}
          {canViewFinancials && <FinancialSummaryCards />}
        </div>
      )}

      {canViewFinancials && (
        <div className="grid gap-4 lg:grid-cols-2">
          <RevenueExpenseChart />
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        {canViewInventory && <LowStockCard />}
        {canViewDashboard && <RecentTransactionsCard />}
        {canViewDashboard && <PendingTasksCard />}
      </div>
    </div>
  )
}
