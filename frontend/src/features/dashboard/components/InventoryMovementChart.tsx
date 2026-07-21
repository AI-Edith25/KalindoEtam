import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Boxes } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { EmptyState } from '@/components/shared/EmptyState'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate, formatNumber } from '@/lib/utils'
import { fetchInventoryMovement } from '../api/dashboardApi'

/** Stock in vs out per day, last 30 days — distinguishes "low stock from a sales spike" from "low stock from a receiving delay," which the current-balance number alone can't show. See docs/DASHBOARD_DESIGN.md §5. */
export function InventoryMovementChart() {
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({ queryKey: ['dashboard', 'inventory-movement'], queryFn: fetchInventoryMovement })

  return (
    <Card className="cursor-pointer transition-colors hover:bg-accent/50" onClick={() => navigate('/inventory/stock-ledger')}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Boxes className="size-4 text-primary" />
          Inventory Movement (30 days)
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-64 w-full" />
        ) : !data || data.length === 0 ? (
          <EmptyState message="No stock movement in this period." />
        ) : (
          <ResponsiveContainer width="100%" height={256}>
            <BarChart data={data} onClick={(event) => event && navigate('/inventory/stock-ledger')}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
              <XAxis dataKey="date" tickFormatter={(value: string) => formatDate(value)} fontSize={12} />
              <YAxis tickFormatter={(value: number) => formatNumber(value)} width={50} fontSize={12} />
              <Tooltip labelFormatter={(value) => formatDate(String(value))} formatter={(value) => formatNumber(Number(value ?? 0))} />
              <Legend />
              <Bar dataKey="stock_in" name="Stock In" fill="#16a34a" />
              <Bar dataKey="stock_out" name="Stock Out" fill="#dc2626" />
            </BarChart>
          </ResponsiveContainer>
        )}
      </CardContent>
    </Card>
  )
}
