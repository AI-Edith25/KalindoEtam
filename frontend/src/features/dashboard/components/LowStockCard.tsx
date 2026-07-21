import { useQuery } from '@tanstack/react-query'
import { AlertCircle } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { formatNumber } from '@/lib/utils'
import { fetchLowStockItems } from '../api/dashboardApi'
import type { LowStockItem } from '../types'

const LOW_STOCK_THRESHOLD = 10

const columns: DataTableColumn<LowStockItem>[] = [
  { header: 'Code', accessor: (row) => row.item_code },
  { header: 'Item', accessor: (row) => row.item_name },
  { header: 'Group', accessor: (row) => row.item_group?.name ?? '—' },
  {
    header: 'Stock',
    accessor: (row) => (
      <span className={row.current_stock === 0 ? 'font-medium text-destructive' : 'font-medium text-amber-600'}>
        {formatNumber(row.current_stock)} {row.uom?.symbol ?? row.uom?.name ?? ''}
      </span>
    ),
    className: 'text-right',
  },
]

export function LowStockCard() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['dashboard', 'low-stock-items', LOW_STOCK_THRESHOLD],
    queryFn: () => fetchLowStockItems(LOW_STOCK_THRESHOLD),
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <AlertCircle className="size-4 text-amber-500" />
          Low Stock Items
          <span className="font-normal text-muted-foreground">(≤ {LOW_STOCK_THRESHOLD})</span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <DataTable
          columns={columns}
          data={data?.data ?? []}
          rowKey={(row) => row.id}
          isLoading={isLoading}
          isError={isError}
          onRetry={() => refetch()}
          emptyMessage="No items are low on stock."
        />
      </CardContent>
    </Card>
  )
}
