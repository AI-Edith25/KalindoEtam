import { useQuery } from '@tanstack/react-query'
import { History } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchRecentTransactions } from '../api/dashboardApi'
import type { RecentTransaction } from '../types'

const RECENT_LIMIT = 10

const TYPE_LABELS: Record<string, string> = {
  purchase_order: 'Purchase Order',
  goods_receipt: 'Goods Receipt',
  sales_order: 'Sales Order',
  delivery: 'Delivery',
  payment_entry: 'Payment Entry',
  receipt_entry: 'Receipt Entry',
}

const columns: DataTableColumn<RecentTransaction>[] = [
  { header: 'Type', accessor: (row) => TYPE_LABELS[row.type] ?? row.type },
  { header: 'Document', accessor: (row) => row.document_number ?? '—' },
  { header: 'Date', accessor: (row) => formatDate(row.date) },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
  { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
]

export function RecentTransactionsCard() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['dashboard', 'recent-transactions', RECENT_LIMIT],
    queryFn: () => fetchRecentTransactions(RECENT_LIMIT),
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <History className="size-4 text-primary" />
          Recent Transactions
        </CardTitle>
      </CardHeader>
      <CardContent>
        <DataTable
          columns={columns}
          data={data ?? []}
          rowKey={(row) => `${row.type}-${row.document_number}-${row.created_at}`}
          isLoading={isLoading}
          isError={isError}
          onRetry={() => refetch()}
          emptyMessage="No recent transactions yet."
        />
      </CardContent>
    </Card>
  )
}
