import { useQuery } from '@tanstack/react-query'
import { Landmark } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { formatCurrency } from '@/lib/utils'
import { fetchBankBalancing } from '../api/dashboardApi'
import type { BankReconciliationSummary, BankReconciliationStatus } from '@/features/bank-reconciliation/types'

const STATUS_LABEL: Record<BankReconciliationStatus, string> = {
  balanced: 'Balanced',
  unbalanced: 'Unbalanced',
  not_uploaded: 'Mutasi bank belum di upload',
}

const columns: DataTableColumn<BankReconciliationSummary>[] = [
  { header: 'Bank Account', accessor: (row) => row.bank_account_name ?? '-' },
  {
    header: 'System (Dr/Cr)',
    accessor: (row) => (row.status === 'not_uploaded' ? '-' : `${formatCurrency(row.system_debit_total)} / ${formatCurrency(row.system_credit_total)}`),
  },
  {
    header: 'Statement (Dr/Cr)',
    accessor: (row) => (row.status === 'not_uploaded' ? '-' : `${formatCurrency(row.statement_debit_total)} / ${formatCurrency(row.statement_credit_total)}`),
  },
  {
    header: 'Status',
    accessor: (row) => (
      <Badge variant={row.status === 'balanced' ? 'default' : row.status === 'unbalanced' ? 'destructive' : 'secondary'}>
        {STATUS_LABEL[row.status]}
      </Badge>
    ),
  },
]

/** Today's per-bank-account balancing -- see BankReconciliationDetailPage for the full daily table + drill-down. */
export function BankBalancingCard() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['dashboard', 'bank-balancing'],
    queryFn: () => fetchBankBalancing(),
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Landmark className="size-4 text-primary" />
          Bank Balancing
        </CardTitle>
      </CardHeader>
      <CardContent>
        <DataTable
          columns={columns}
          data={data ?? []}
          rowKey={(row) => row.id}
          isLoading={isLoading}
          isError={isError}
          onRetry={() => refetch()}
          emptyMessage="No bank accounts configured."
        />
      </CardContent>
    </Card>
  )
}
