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
import { cancelOpeningStock, deleteOpeningStock, fetchOpeningStock, submitOpeningStock } from '../api/openingStockApi'
import type { OpeningStockItem } from '../types'

const lineColumns: DataTableColumn<OpeningStockItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatQty(row.qty, row.qty_category), className: 'text-right' },
  { header: 'Unit Cost', accessor: (row) => formatCurrency(row.unit_cost), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right font-medium' },
]

/** Read-only, section-grouped — same shell as StockAdjustmentDetailPage, plus a totals footer and a working Cancel action (Opening Stock is the one document in this app where cancel() isn't forbidden). */
export function OpeningStockDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const openingStockQuery = useQuery({
    queryKey: ['opening-stocks', id],
    queryFn: () => fetchOpeningStock(id!),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['opening-stocks'] })
    queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
    queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
    queryClient.invalidateQueries({ queryKey: ['fifo-layers'] })
  }

  const submitMutation = useMutation({
    mutationFn: () => submitOpeningStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Opening Stock submitted — layers created.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: () => cancelOpeningStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Opening Stock cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteOpeningStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Opening Stock deleted.')
      navigate('/inventory/opening-stock')
    },
    onError: (error) => toastApiError(error),
  })

  if (openingStockQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const openingStock = openingStockQuery.data
  if (!openingStock) return null

  const total = openingStock.items.reduce((sum, line) => sum + Number(line.amount), 0)

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={openingStock.document_number ?? 'Opening Stock'}
        description="Opening stock document details."
        actions={
          openingStock.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/inventory/opening-stock/${openingStock.id}/edit`)}>
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
          ) : openingStock.status === 'submitted' ? (
            <Button variant="destructive" onClick={() => cancelMutation.mutate()} disabled={cancelMutation.isPending}>
              {cancelMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <XCircle className="size-4" />}
              Cancel
            </Button>
          ) : undefined
        }
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Opening Stock Details</CardTitle>
          <StatusBadge status={openingStock.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Document Number" value={openingStock.document_number ?? '—'} />
            <DetailField label="Warehouse" value={openingStock.warehouse?.name ?? '—'} />
            <DetailField label="Cutoff Date" value={formatDate(openingStock.cutoff_date)} />
            <DetailField label="Notes" value={openingStock.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <DataTable columns={lineColumns} data={openingStock.items} rowKey={(row) => row.id} emptyMessage="No line items." />
          <p className="text-right text-sm font-medium">Total: {formatCurrency(total)}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(openingStock.created_at)} />
            <DetailField label="Submitted" value={openingStock.submitted_at ? formatDate(openingStock.submitted_at) : '—'} />
            <DetailField label="Cancelled" value={openingStock.cancelled_at ? formatDate(openingStock.cancelled_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={openingStock.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
