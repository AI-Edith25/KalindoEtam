import { useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Save, Send } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { parseLocaleQty } from '@/shared/lib/qty'
import { createReceiptStock, fetchReceiptStock, submitReceiptStock, updateReceiptStock } from '../api/receiptStockApi'
import { ReceiptStockLineItemTable } from '../components/ReceiptStockLineItemTable'
import { emptyReceiptStockEditorValues, receiptStockFormSchema, type ReceiptStockEditorValues } from '../lib/receiptStockFormSchema'
import type { ReceiptStockFormValues } from '../types'

export function ReceiptStockEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const receiptStockQuery = useQuery({
    queryKey: ['receipt-stocks', id],
    queryFn: () => fetchReceiptStock(id!),
    enabled: isEdit,
  })

  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const warehouseOptions = warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []

  const form = useForm<ReceiptStockEditorValues>({
    resolver: zodResolver(receiptStockFormSchema),
    defaultValues: emptyReceiptStockEditorValues,
  })

  useEffect(() => {
    const receiptStock = receiptStockQuery.data
    if (!receiptStock) return

    if (receiptStock.status !== 'draft') {
      toast.error('Only draft Receipt Stock documents can be edited.')
      navigate(`/inventory/receipt-stock/${receiptStock.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [receiptStockQuery.data])

  useEffect(() => {
    const receiptStock = receiptStockQuery.data
    if (!isEdit || !receiptStock) return

    form.reset({
      warehouse_id: receiptStock.warehouse_id,
      receipt_date: receiptStock.receipt_date,
      remarks: receiptStock.remarks ?? '',
      items: receiptStock.items.map((line) => ({
        item_id: line.item_id,
        item_code: line.item_code,
        item_name: line.item_name,
        qtyCategory: line.qty_category,
        qty: String(line.qty),
        unitCost: String(line.unit_cost),
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [receiptStockQuery.data])

  const toPayload = (values: ReceiptStockEditorValues): ReceiptStockFormValues => ({
    warehouse_id: values.warehouse_id,
    receipt_date: values.receipt_date,
    remarks: values.remarks || null,
    items: values.items.map((line) => ({
      item_id: line.item_id,
      qty: parseLocaleQty(line.qty),
      unit_cost: parseLocaleQty(line.unitCost),
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: ReceiptStockEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateReceiptStock(id!, payload) : createReceiptStock(payload)
    },
    onSuccess: (receiptStock) => {
      queryClient.invalidateQueries({ queryKey: ['receipt-stocks'] })
      toast.success(isEdit ? 'Receipt Stock details updated.' : 'Receipt Stock recorded. Submit to create the FIFO layer.')
      if (!isEdit) {
        navigate(`/inventory/receipt-stock/${receiptStock.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitReceiptStock(id!),
    onSuccess: (receiptStock) => {
      queryClient.invalidateQueries({ queryKey: ['receipt-stocks'] })
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Receipt Stock submitted — layer created.')
      navigate(`/inventory/receipt-stock/${receiptStock.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  if (isEdit && receiptStockQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${receiptStockQuery.data?.document_number ?? 'Receipt Stock'}` : 'New Receipt Stock'}
        description="Record stock coming in outside of a purchase — returns from usage, stock found, supplier gifts."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Receipt Stock Details</CardTitle>
              <StatusBadge status={isEdit ? (receiptStockQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="warehouse_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Warehouse</FormLabel>
                    <SearchableSelect
                      options={warehouseOptions}
                      value={field.value}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={warehouses.isLoading}
                      clearable={false}
                      placeholder="Select warehouse"
                      aria-label="Warehouse"
                    />
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="receipt_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="remarks"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Notes</FormLabel>
                    <FormControl>
                      <Textarea placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Line Items</CardTitle>
            </CardHeader>
            <CardContent>
              <ReceiptStockLineItemTable form={form} />
              {form.formState.errors.items?.message && (
                <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.message}</p>
              )}
            </CardContent>
          </Card>

          <p className="text-right text-sm text-muted-foreground">
            Recording quantities here doesn't move stock yet — Submit is what creates the FIFO layer.
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/inventory/receipt-stock')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && receiptStockQuery.data?.status === 'draft' && (
              <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Submit
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
