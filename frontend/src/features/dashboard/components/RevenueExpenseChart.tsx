import { useQueries } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { DollarSign } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { EmptyState } from '@/components/shared/EmptyState'
import { Skeleton } from '@/components/ui/skeleton'
import { formatCurrency } from '@/lib/utils'
import { fetchFinancialSummary } from '../api/dashboardApi'

const MONTH_LABEL = new Intl.DateTimeFormat('en-US', { month: 'short', year: '2-digit' })

/** Last 6 months of Revenue vs Expense — an at-a-glance profitability trend without opening the full Profit & Loss report. Each bar-pair is one call to the same financial-summary endpoint the Financial Summary cards use (ProfitLossService, reused as-is — no GL math here). See docs/DASHBOARD_DESIGN.md §5. */
export function RevenueExpenseChart() {
  const navigate = useNavigate()

  const months = Array.from({ length: 6 }, (_, index) => {
    const monthsAgo = 5 - index
    const start = new Date()
    start.setDate(1)
    start.setMonth(start.getMonth() - monthsAgo)
    const end = new Date(start.getFullYear(), start.getMonth() + 1, 0)
    const toIso = (d: Date) => d.toISOString().slice(0, 10)
    return { label: MONTH_LABEL.format(start), date_from: toIso(start), date_to: toIso(end) }
  })

  const results = useQueries({
    queries: months.map((month) => ({
      queryKey: ['dashboard', 'financial-summary', month.date_from, month.date_to],
      queryFn: () => fetchFinancialSummary({ date_from: month.date_from, date_to: month.date_to }),
    })),
  })

  const isLoading = results.some((result) => result.isLoading)
  const data = months.map((month, index) => ({
    label: month.label,
    revenue: results[index].data?.revenue_total ?? 0,
    expense: results[index].data?.expense_total ?? 0,
  }))
  const hasAnyData = data.some((row) => row.revenue !== 0 || row.expense !== 0)

  return (
    <Card className="cursor-pointer transition-colors hover:bg-accent/50" onClick={() => navigate('/accounting/profit-loss')}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <DollarSign className="size-4 text-primary" />
          Revenue vs Expense (6 months)
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-64 w-full" />
        ) : !hasAnyData ? (
          <EmptyState message="No posted revenue or expense yet." />
        ) : (
          <ResponsiveContainer width="100%" height={256}>
            <BarChart data={data} onClick={(event) => event && navigate('/accounting/profit-loss')}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
              <XAxis dataKey="label" fontSize={12} />
              <YAxis tickFormatter={(value: number) => formatCurrency(value)} width={90} fontSize={12} />
              <Tooltip formatter={(value) => formatCurrency(Number(value ?? 0))} />
              <Legend />
              <Bar dataKey="revenue" name="Revenue" fill="#16a34a" />
              <Bar dataKey="expense" name="Expense" fill="#dc2626" />
            </BarChart>
          </ResponsiveContainer>
        )}
      </CardContent>
    </Card>
  )
}
