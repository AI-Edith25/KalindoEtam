import { useQuery } from '@tanstack/react-query'
import { DollarSign, TrendingUp } from 'lucide-react'
import { SummaryCard } from './SummaryCard'
import { fetchFinancialSummary } from '../api/dashboardApi'
import { formatCurrency } from '@/lib/utils'

/**
 * Revenue/Expense/Net Profit for month-to-date — reuses
 * ProfitLossService::summarize() via DashboardService::financialSummary(),
 * never recomputed here. Drill-down: Revenue → Profit & Loss, per the
 * approved ticket. See docs/DASHBOARD_DESIGN.md §3.
 */
export function FinancialSummaryCards() {
  const { data, isLoading } = useQuery({ queryKey: ['dashboard', 'financial-summary'], queryFn: () => fetchFinancialSummary() })

  return (
    <>
      <SummaryCard
        title="Revenue (MTD)"
        value={formatCurrency(data?.revenue_total ?? 0)}
        description="Month to date"
        icon={TrendingUp}
        isLoading={isLoading}
        to="/accounting/profit-loss"
      />
      <SummaryCard
        title="Net Profit (MTD)"
        value={formatCurrency(data?.net_profit ?? 0)}
        description="Month to date"
        icon={DollarSign}
        isLoading={isLoading}
        to="/accounting/profit-loss"
      />
    </>
  )
}
