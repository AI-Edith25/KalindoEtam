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
import { formatDate, formatNumber } from '@/lib/utils'
import { deleteStockTransfer, fetchStockTransfer, submitStockTransfer } from '../api/stockTransferApi'
import type { StockTransferItem } from '../types'

const lineColumns: DataTableColumn<StockTransferItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'UOM', accessor: (row) => row.uom },
  { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
]

/** Read-only, section-grouped — same shell as StockAdjustmentDetailPage, minus a totals footer. */
export function StockTransferDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const transferQuery = useQuery({
    queryKey: ['stock-transfers', id],
    queryFn: () => fetchStockTransfer(id!),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['stock-transfers'] })

  const submitMutation = useMutation({
    mutationFn: () => submitStockTransfer(id!),
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Transfer confirmed — stock moved.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteStockTransfer(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Stock Transfer deleted.')
      navigate('/inventory/transfers')
    },
    onError: (error) => toastApiError(error),
  })

  if (transferQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const transfer = transferQuery.data
  if (!transfer) return null

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={transfer.document_number ?? 'Stock Transfer'}
        description="Stock transfer details."
        actions={
          transfer.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/inventory/transfers/${transfer.id}/edit`)}>
                <Pencil className="size-4" />
                Edit
              </Button>
              <Button onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Transfer
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
          <CardTitle>Transfer Details</CardTitle>
          <StatusBadge status={transfer.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Document Number" value={transfer.document_number ?? '—'} />
            <DetailField label="Transfer Date" value={formatDate(transfer.transfer_date)} />
            <DetailField label="Source Warehouse" value={transfer.source_warehouse?.name ?? '—'} />
            <DetailField label="Destination Warehouse" value={transfer.destination_warehouse?.name ?? '—'} />
            <DetailField label="Notes" value={transfer.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable columns={lineColumns} data={transfer.items} rowKey={(row) => row.id} emptyMessage="No line items." />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(transfer.created_at)} />
            <DetailField label="Submitted" value={transfer.submitted_at ? formatDate(transfer.submitted_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={transfer.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
