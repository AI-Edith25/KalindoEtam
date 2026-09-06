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
import { cancelIssueStock, deleteIssueStock, fetchIssueStock, submitIssueStock } from '../api/issueStockApi'
import type { IssueStockItem } from '../types'

const lineColumns: DataTableColumn<IssueStockItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatQty(row.qty, row.qty_category), className: 'text-right' },
  // Null while Draft — only Submit's FIFO consumption fills these in.
  { header: 'Unit Cost', accessor: (row) => (row.unit_cost === null ? '—' : formatCurrency(row.unit_cost)), className: 'text-right' },
  { header: 'Amount', accessor: (row) => (row.amount === null ? '—' : formatCurrency(row.amount)), className: 'text-right font-medium' },
]

/** Read-only, section-grouped — same shell as OpeningStockDetailPage, including a working Cancel action. */
export function IssueStockDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const issueStockQuery = useQuery({
    queryKey: ['issue-stocks', id],
    queryFn: () => fetchIssueStock(id!),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['issue-stocks'] })
    queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
    queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
    queryClient.invalidateQueries({ queryKey: ['fifo-layers'] })
  }

  const submitMutation = useMutation({
    mutationFn: () => submitIssueStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Issue Stock submitted — FIFO layers consumed.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: () => cancelIssueStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Issue Stock cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteIssueStock(id!),
    onSuccess: () => {
      invalidate()
      toast.success('Issue Stock deleted.')
      navigate('/inventory/issue-stock')
    },
    onError: (error) => toastApiError(error),
  })

  if (issueStockQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const issueStock = issueStockQuery.data
  if (!issueStock) return null

  const total = issueStock.items.reduce((sum, line) => sum + Number(line.amount ?? 0), 0)

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={issueStock.document_number ?? 'Issue Stock'}
        description="Issue stock document details."
        actions={
          issueStock.status === 'draft' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={() => navigate(`/inventory/issue-stock/${issueStock.id}/edit`)}>
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
          ) : issueStock.status === 'submitted' ? (
            <Button variant="destructive" onClick={() => cancelMutation.mutate()} disabled={cancelMutation.isPending}>
              {cancelMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <XCircle className="size-4" />}
              Cancel
            </Button>
          ) : undefined
        }
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Issue Stock Details</CardTitle>
          <StatusBadge status={issueStock.status} />
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Document Number" value={issueStock.document_number ?? '—'} />
            <DetailField label="Warehouse" value={issueStock.warehouse?.name ?? '—'} />
            <DetailField label="Date" value={formatDate(issueStock.issue_date)} />
            <DetailField label="Notes" value={issueStock.remarks || '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <DataTable columns={lineColumns} data={issueStock.items} rowKey={(row) => row.id} emptyMessage="No line items." />
          <p className="text-right text-sm font-medium">Total: {issueStock.status === 'draft' ? '—' : formatCurrency(total)}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Audit Information</CardTitle>
        </CardHeader>
        <CardContent>
          <DetailSection>
            <DetailField label="Created" value={formatDate(issueStock.created_at)} />
            <DetailField label="Submitted" value={issueStock.submitted_at ? formatDate(issueStock.submitted_at) : '—'} />
            <DetailField label="Cancelled" value={issueStock.cancelled_at ? formatDate(issueStock.cancelled_at) : '—'} />
          </DetailSection>
        </CardContent>
      </Card>

      <DeleteDialog
        open={confirmingDelete}
        onOpenChange={setConfirmingDelete}
        itemLabel={issueStock.document_number ?? undefined}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  )
}
