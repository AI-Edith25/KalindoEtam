import { useEffect, useMemo } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Save, Send } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchItemsLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { fetchStockBalances } from '../api/stockApi'
import {
  createStockTransfer,
  fetchStockTransfer,
  submitStockTransfer,
  updateStockTransfer,
} from '../api/stockTransferApi'
import { StockTransferLineItemTable } from '../components/StockTransferLineItemTable'
import {
  emptyStockTransferEditorValues,
  stockTransferFormSchema,
  type StockTransferEditorValues,
} from '../lib/stockTransferFormSchema'
import type { StockTransferFormValues } from '../types'

export function StockTransferEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const transferQuery = useQuery({
    queryKey: ['stock-transfers', id],
    queryFn: () => fetchStockTransfer(id!),
    enabled: isEdit,
  })

  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: fetchItemsLookup })

  const form = useForm<StockTransferEditorValues>({
    resolver: zodResolver(stockTransferFormSchema),
    defaultValues: emptyStockTransferEditorValues,
  })

  const sourceWarehouseId = useWatch({ control: form.control, name: 'source_warehouse_id' })
  const watchedItems = useWatch({ control: form.control, name: 'items' })

  const itemIds = useMemo(
    () => Array.from(new Set((watchedItems ?? []).map((line) => line.item_id).filter((itemId) => itemId))),
    [watchedItems],
  )

  // Available qty is scoped to the source warehouse — refetches whenever the source or the set of selected items changes. Reuses the same bulk lookup as Delivery/Stock Adjustment.
  const stockBalancesQuery = useQuery({
    queryKey: ['stock-balances', sourceWarehouseId, itemIds],
    queryFn: () => fetchStockBalances({ warehouse_id: sourceWarehouseId, item_ids: itemIds }),
    enabled: !!sourceWarehouseId && itemIds.length > 0,
  })

  useEffect(() => {
    const transfer = transferQuery.data
    if (!transfer) return

    if (transfer.status !== 'draft') {
      toast.error('Only draft stock transfers can be edited.')
      navigate(`/inventory/transfers/${transfer.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [transferQuery.data])

  // Populates the form once from the fetched transfer (edit mode only) — create mode starts empty and rows are added freely.
  useEffect(() => {
    const transfer = transferQuery.data
    if (!isEdit || !transfer) return

    form.reset({
      source_warehouse_id: transfer.source_warehouse_id,
      destination_warehouse_id: transfer.destination_warehouse_id,
      transfer_date: transfer.transfer_date,
      remarks: transfer.remarks ?? '',
      items: transfer.items.map((line) => ({
        item_id: line.item_id,
        item_code: line.item_code,
        item_name: line.item_name,
        availableQty: 0,
        qty: String(line.qty),
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [transferQuery.data])

  // Patches availableQty into every current row once the balance lookup resolves — deliberately separate from the reset above (and from row add/remove) so changing the source warehouse, or adding another item, never wipes Qty the user already entered.
  useEffect(() => {
    const balances = stockBalancesQuery.data
    if (!balances) return

    form.getValues('items').forEach((line, index) => {
      if (line.item_id && balances[line.item_id] !== undefined) {
        form.setValue(`items.${index}.availableQty`, balances[line.item_id], { shouldValidate: true })
      }
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stockBalancesQuery.data])

  const toPayload = (values: StockTransferEditorValues): StockTransferFormValues => ({
    source_warehouse_id: values.source_warehouse_id,
    destination_warehouse_id: values.destination_warehouse_id,
    transfer_date: values.transfer_date,
    remarks: values.remarks || null,
    items: values.items.map((line) => ({
      item_id: line.item_id,
      qty: Number(line.qty),
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: StockTransferEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateStockTransfer(id!, payload) : createStockTransfer(payload)
    },
    onSuccess: (transfer) => {
      queryClient.invalidateQueries({ queryKey: ['stock-transfers'] })
      toast.success(isEdit ? 'Transfer details updated.' : 'Transfer recorded. Confirm to move stock.')
      if (!isEdit) {
        navigate(`/inventory/transfers/${transfer.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitStockTransfer(id!),
    onSuccess: (transfer) => {
      queryClient.invalidateQueries({ queryKey: ['stock-transfers'] })
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Transfer confirmed — stock moved.')
      navigate(`/inventory/transfers/${transfer.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  if (isEdit && transferQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${transferQuery.data?.document_number ?? 'Stock Transfer'}` : 'New Stock Transfer'}
        description="Move stock directly from one warehouse to another."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Transfer Details</CardTitle>
              <StatusBadge status={isEdit ? (transferQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="source_warehouse_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Source Warehouse</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={warehouses.isLoading ? 'Loading…' : 'Select warehouse'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {warehouses.data?.map((warehouse) => (
                          <SelectItem key={warehouse.id} value={warehouse.id}>
                            {warehouse.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="destination_warehouse_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Destination Warehouse</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={warehouses.isLoading ? 'Loading…' : 'Select warehouse'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {warehouses.data
                          ?.filter((warehouse) => warehouse.id !== sourceWarehouseId)
                          .map((warehouse) => (
                            <SelectItem key={warehouse.id} value={warehouse.id}>
                              {warehouse.name}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="transfer_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Transfer Date</FormLabel>
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
              {!sourceWarehouseId && (
                <p className="mb-3 text-sm text-muted-foreground">Select a source warehouse to see available quantities for each item.</p>
              )}
              <StockTransferLineItemTable form={form} items={items.data ?? []} itemsLoading={items.isLoading} />
              {form.formState.errors.items?.message && (
                <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.message}</p>
              )}
            </CardContent>
          </Card>

          <p className="text-right text-sm text-muted-foreground">
            {isEdit && transferQuery.data?.status === 'draft'
              ? 'Recording a Transfer captures the line items. Confirming moves the stock.'
              : 'Recording quantities here doesn’t move stock yet — you’ll confirm the transfer on the next screen.'}
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/inventory/transfers')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Record Transfer
            </Button>
            {isEdit && transferQuery.data?.status === 'draft' && (
              <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Transfer
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
