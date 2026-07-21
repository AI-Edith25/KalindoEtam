import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { TrendingUp } from 'lucide-react'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { EmptyState } from '@/components/shared/EmptyState'
import { Skeleton } from '@/components/ui/skeleton'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchSalesTrend } from '../api/dashboardApi'

/** Daily sales totals, last 30 days — spots demand shifts a single "today" number can't show. See docs/DASHBOARD_DESIGN.md §5. */
export function SalesTrendChart() {
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({ queryKey: ['dashboard', 'sales-trend'], queryFn: fetchSalesTrend })

  return (
    <Card className="cursor-pointer transition-colors hover:bg-accent/50" onClick={() => navigate('/reports/sales')}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <TrendingUp className="size-4 text-primary" />
          Sales Trend (30 days)
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-64 w-full" />
        ) : !data || data.length === 0 ? (
          <EmptyState message="No sales in this period." />
        ) : (
          <ResponsiveContainer width="100%" height={256}>
            <LineChart data={data} onClick={(event) => event && navigate('/reports/sales')}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
              <XAxis dataKey="date" tickFormatter={(value: string) => formatDate(value)} fontSize={12} />
              <YAxis tickFormatter={(value: number) => formatCurrency(value)} width={90} fontSize={12} />
              <Tooltip labelFormatter={(value) => formatDate(String(value))} formatter={(value) => formatCurrency(Number(value ?? 0))} />
              <Line type="monotone" dataKey="total" name="Sales" stroke="var(--color-primary)" strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        )}
      </CardContent>
    </Card>
  )
}
