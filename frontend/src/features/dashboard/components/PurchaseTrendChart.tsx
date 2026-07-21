import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { TrendingDown } from 'lucide-react'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { EmptyState } from '@/components/shared/EmptyState'
import { Skeleton } from '@/components/ui/skeleton'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchPurchaseTrend } from '../api/dashboardApi'

/** Daily purchase totals, last 30 days — shows spend pace and restocking rhythm over time. See docs/DASHBOARD_DESIGN.md §5. */
export function PurchaseTrendChart() {
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({ queryKey: ['dashboard', 'purchase-trend'], queryFn: fetchPurchaseTrend })

  return (
    <Card className="cursor-pointer transition-colors hover:bg-accent/50" onClick={() => navigate('/reports/purchase')}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <TrendingDown className="size-4 text-primary" />
          Purchase Trend (30 days)
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-64 w-full" />
        ) : !data || data.length === 0 ? (
          <EmptyState message="No purchases in this period." />
        ) : (
          <ResponsiveContainer width="100%" height={256}>
            <LineChart data={data} onClick={(event) => event && navigate('/reports/purchase')}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
              <XAxis dataKey="date" tickFormatter={(value: string) => formatDate(value)} fontSize={12} />
              <YAxis tickFormatter={(value: number) => formatCurrency(value)} width={90} fontSize={12} />
              <Tooltip labelFormatter={(value) => formatDate(String(value))} formatter={(value) => formatCurrency(Number(value ?? 0))} />
              <Line type="monotone" dataKey="total" name="Purchases" stroke="var(--color-primary)" strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        )}
      </CardContent>
    </Card>
  )
}
