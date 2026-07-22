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
  createStockAdjustment,
  fetchStockAdjustment,
  submitStockAdjustment,
  updateStockAdjustment,
} from '../api/stockAdjustmentApi'
import { StockAdjustmentLineItemTable } from '../components/StockAdjustmentLineItemTable'
import {
  emptyStockAdjustmentEditorValues,
  stockAdjustmentFormSchema,
  type StockAdjustmentEditorValues,
} from '../lib/stockAdjustmentFormSchema'
import type { StockAdjustmentFormValues } from '../types'

export function StockAdjustmentEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const adjustmentQuery = useQuery({
    queryKey: ['stock-adjustments', id],
    queryFn: () => fetchStockAdjustment(id!),
    enabled: isEdit,
  })

  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const items = useQuery({ queryKey: ['items-lookup'], queryFn: fetchItemsLookup })

  const form = useForm<StockAdjustmentEditorValues>({
    resolver: zodResolver(stockAdjustmentFormSchema),
    defaultValues: emptyStockAdjustmentEditorValues,
  })

  const warehouseId = useWatch({ control: form.control, name: 'warehouse_id' })
  const watchedItems = useWatch({ control: form.control, name: 'items' })

  const itemIds = useMemo(
    () => Array.from(new Set((watchedItems ?? []).map((line) => line.item_id).filter((itemId) => itemId))),
    [watchedItems],
  )

  // Available/System qty is warehouse-scoped, so it can only be known once a warehouse is chosen — refetches whenever the warehouse or the set of selected items changes. Reuses the bulk lookup built for Delivery's "Available Stock" column.
  const stockBalancesQuery = useQuery({
    queryKey: ['stock-balances', warehouseId, itemIds],
    queryFn: () => fetchStockBalances({ warehouse_id: warehouseId, item_ids: itemIds }),
    enabled: !!warehouseId && itemIds.length > 0,
  })

  useEffect(() => {
    const adjustment = adjustmentQuery.data
    if (!adjustment) return

    if (adjustment.status !== 'draft') {
      toast.error('Only draft stock adjustments can be edited.')
      navigate(`/inventory/adjustments/${adjustment.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [adjustmentQuery.data])

  // Populates the form once from the fetched adjustment (edit mode only) — create mode starts empty and rows are added freely.
  useEffect(() => {
    const adjustment = adjustmentQuery.data
    if (!isEdit || !adjustment) return

    form.reset({
      warehouse_id: adjustment.warehouse_id,
      adjustment_date: adjustment.adjustment_date,
      remarks: adjustment.remarks ?? '',
      items: adjustment.items.map((line) => ({
        item_id: line.item_id,
        item_code: line.item_code,
        item_name: line.item_name,
        systemQty: line.system_qty,
        countedQty: String(line.counted_qty),
        reason: line.reason,
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [adjustmentQuery.data])

  // Patches systemQty into every current row once the balance lookup resolves — deliberately separate from the reset above (and from row add/remove) so changing the warehouse, or adding another item, never wipes Physical Qty/Reason the user already entered.
  useEffect(() => {
    const balances = stockBalancesQuery.data
    if (!balances) return

    form.getValues('items').forEach((line, index) => {
      if (line.item_id && balances[line.item_id] !== undefined) {
        form.setValue(`items.${index}.systemQty`, balances[line.item_id], { shouldValidate: true })
      }
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stockBalancesQuery.data])

  const toPayload = (values: StockAdjustmentEditorValues): StockAdjustmentFormValues => ({
    warehouse_id: values.warehouse_id,
    adjustment_date: values.adjustment_date,
    remarks: values.remarks || null,
    items: values.items.map((line) => ({
      item_id: line.item_id,
      counted_qty: Number(line.countedQty),
      reason: line.reason,
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: StockAdjustmentEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateStockAdjustment(id!, payload) : createStockAdjustment(payload)
    },
    onSuccess: (adjustment) => {
      queryClient.invalidateQueries({ queryKey: ['stock-adjustments'] })
      toast.success(isEdit ? 'Adjustment details updated.' : 'Adjustment recorded. Confirm to update stock.')
      if (!isEdit) {
        navigate(`/inventory/adjustments/${adjustment.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitStockAdjustment(id!),
    onSuccess: (adjustment) => {
      queryClient.invalidateQueries({ queryKey: ['stock-adjustments'] })
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Adjustment confirmed — stock updated.')
      navigate(`/inventory/adjustments/${adjustment.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  if (isEdit && adjustmentQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${adjustmentQuery.data?.document_number ?? 'Stock Adjustment'}` : 'New Stock Adjustment'}
        description="Reconcile a physical count against the system's recorded stock."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Adjustment Details</CardTitle>
              <StatusBadge status={isEdit ? (adjustmentQuery.data?.status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="warehouse_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Warehouse</FormLabel>
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
                name="adjustment_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Adjustment Date</FormLabel>
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
              {!warehouseId && (
                <p className="mb-3 text-sm text-muted-foreground">Select a warehouse to see system quantities for each item.</p>
              )}
              <StockAdjustmentLineItemTable form={form} items={items.data ?? []} itemsLoading={items.isLoading} />
              {form.formState.errors.items?.message && (
                <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.message}</p>
              )}
            </CardContent>
          </Card>

          <p className="text-right text-sm text-muted-foreground">
            {isEdit && adjustmentQuery.data?.status === 'draft'
              ? 'Recording an Adjustment captures the physical count. Confirming updates stock to match it.'
              : 'Recording quantities here doesn’t move stock yet — you’ll confirm the adjustment on the next screen.'}
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/inventory/adjustments')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Record Adjustment
            </Button>
            {isEdit && adjustmentQuery.data?.status === 'draft' && (
              <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Confirm Adjustment
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
