import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Pencil, Send, Trash2, XCircle } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { formatQty } from '@/shared/lib/qty'
import { cancelReceiptStock, deleteReceiptStock, fetchReceiptStock, submitReceiptStock } from '../api/receiptStockApi'
import type { ReceiptStockItem } from '../types'

const lineColumns: DataTableColumn<ReceiptStockItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatQty(row.qty, row.qty_category), className: 'text-right' },
  { header: 'Unit Cost', accessor: (row) => formatCurrency(row.unit_cost), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right font-medium' },
]

/** Read-only, section-grouped — same shell as OpeningStockDetailPage, including a working Cancel action. */
export function ReceiptStockDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const receiptStockQuery = useQuery({
    queryKey: ['receipt-stocks', id],
    queryFn: () => fetchReceiptStock(id!),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['receipt-stocks'] })
    queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
    queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
    queryClient.invalidateQueries({ queryKey: ['fifo-layers'] })
  }

  const submitMutation = useMutation({
    mutationFn: () => submitReceiptStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Receipt Stock submitted — layer created.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: () => cancelReceiptStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Receipt Stock cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteReceiptStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Receipt Stock deleted.')
      navigate('/inventory/receipt-stock')
    },
    onError: (error) => toastApiError(error),
  })

  if (receiptStockQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const receiptStock = receiptStockQuery.data
  if (!receiptStock) return null

  const total = receiptStock.items.reduce((sum, line) => sum + Number(line.amount), 0)

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={receiptStock.document_number ?? 'Receipt Stock'}
        description="Receipt stock document details."
        actions={
          receiptStock.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/inventory/receipt-stock/${receiptStock.id}/edit`)}>
                <Pencil className="size-4" />
                Edit
              </Button>
              <Button onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Submit
              </Button>
              <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                <Trash2 className="size-4" />
                Delete
              </Button>
            </div>
          ) : receiptStock.status === 'submitted' ? (
            <Button variant="destructive" onClick={() => cancelMutation.mutate()} disabled={cancelMutation.isPending}>
              {cancelMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <XCircle className="size-4" />}
              Cancel
            </Button>
          ) : undefined
        }
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Receipt Stock Details</CardTitle>
          <StatusBadge status={receiptStock.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Document Number" value={receiptStock.document_number ?? '—'} />
            <DetailField label="Warehouse" value={receiptStock.warehouse?.name ?? '—'} />
            <DetailField label="Date" value={formatDate(receiptStock.receipt_date)} />
            <DetailField label="Notes" value={receiptStock.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <DataTable columns={lineColumns} data={receiptStock.items} rowKey={(row) => row.id} emptyMessage="No line items." />
          <p className="text-right text-sm font-medium">Total: {formatCurrency(total)}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(receiptStock.created_at)} />
            <DetailField label="Submitted" value={receiptStock.submitted_at ? formatDate(receiptStock.submitted_at) : '—'} />
            <DetailField label="Cancelled" value={receiptStock.cancelled_at ? formatDate(receiptStock.cancelled_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={receiptStock.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
