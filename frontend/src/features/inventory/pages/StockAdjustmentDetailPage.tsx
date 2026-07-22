import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Pencil, Send, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { toastApiError } from '@/shared/services/errorHandler'
import { cn, formatDate, formatNumber } from '@/lib/utils'
import { deleteStockAdjustment, fetchStockAdjustment, submitStockAdjustment } from '../api/stockAdjustmentApi'
import type { StockAdjustmentItem } from '../types'

const lineColumns: DataTableColumn<StockAdjustmentItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'System Qty', accessor: (row) => formatNumber(row.system_qty), className: 'text-right text-muted-foreground' },
  { header: 'Physical Qty', accessor: (row) => formatNumber(row.counted_qty), className: 'text-right' },
  {
    header: 'Difference',
    accessor: (row) => (
      <span
        className={cn(
          'font-medium',
          row.difference_qty > 0 && 'text-green-600 dark:text-green-400',
          row.difference_qty < 0 && 'text-destructive',
        )}
      >
        {row.difference_qty > 0 ? '+' : ''}
        {formatNumber(row.difference_qty)}
      </span>
    ),
    className: 'text-right',
  },
  { header: 'Reason', accessor: (row) => row.reason },
]

/** Read-only, section-grouped — same shell as GoodsReceiptDetailPage, minus a totals footer (Stock Adjustment has no monetary total). */
export function StockAdjustmentDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const adjustmentQuery = useQuery({
    queryKey: ['stock-adjustments', id],
    queryFn: () => fetchStockAdjustment(id!),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['stock-adjustments'] })

  const submitMutation = useMutation({
    mutationFn: () => submitStockAdjustment(id!),
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Adjustment confirmed — stock updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteStockAdjustment(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Stock Adjustment deleted.')
      navigate('/inventory/adjustments')
    },
    onError: (error) => toastApiError(error),
  })

  if (adjustmentQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const adjustment = adjustmentQuery.data
  if (!adjustment) return null

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={adjustment.document_number ?? 'Stock Adjustment'}
        description="Stock adjustment details."
        actions={
          adjustment.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/inventory/adjustments/${adjustment.id}/edit`)}>
                <Pencil className="size-4" />
                Edit
              </Button>
              <Button onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Adjustment
              </Button>
              <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                <Trash2 className="size-4" />
                Delete
              </Button>
            </div>
          ) : undefined
        }
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Adjustment Details</CardTitle>
          <StatusBadge status={adjustment.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Document Number" value={adjustment.document_number ?? '—'} />
            <DetailField label="Warehouse" value={adjustment.warehouse?.name ?? '—'} />
            <DetailField label="Adjustment Date" value={formatDate(adjustment.adjustment_date)} />
            <DetailField label="Notes" value={adjustment.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={adjustment.items} rowKey={(row) => row.id} emptyMessage="No line items." />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(adjustment.created_at)} />
            <DetailField label="Submitted" value={adjustment.submitted_at ? formatDate(adjustment.submitted_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={adjustment.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
