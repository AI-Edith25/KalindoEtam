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
import { createOpeningStock, fetchOpeningStock, submitOpeningStock, updateOpeningStock } from '../api/openingStockApi'
import { OpeningStockLineItemTable } from '../components/OpeningStockLineItemTable'
import { emptyOpeningStockEditorValues, openingStockFormSchema, type OpeningStockEditorValues } from '../lib/openingStockFormSchema'
import type { OpeningStockFormValues } from '../types'

export function OpeningStockEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const openingStockQuery = useQuery({
    queryKey: ['opening-stocks', id],
    queryFn: () => fetchOpeningStock(id!),
    enabled: isEdit,
  })

  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const warehouseOptions = warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []

  const form = useForm<OpeningStockEditorValues>({
    resolver: zodResolver(openingStockFormSchema),
    defaultValues: emptyOpeningStockEditorValues,
  })

  useEffect(() => {
    const openingStock = openingStockQuery.data
    if (!openingStock) return

    if (openingStock.status !== 'draft') {
      toast.error('Only draft Opening Stock documents can be edited.')
      navigate(`/inventory/opening-stock/${openingStock.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openingStockQuery.data])

  useEffect(() => {
    const openingStock = openingStockQuery.data
    if (!isEdit || !openingStock) return

    form.reset({
      warehouse_id: openingStock.warehouse_id,
      cutoff_date: openingStock.cutoff_date,
      remarks: openingStock.remarks ?? '',
      items: openingStock.items.map((line) => ({
        item_id: line.item_id,
        item_code: line.item_code,
        item_name: line.item_name,
        qtyCategory: line.qty_category,
        qty: String(line.qty),
        unitCost: String(line.unit_cost),
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openingStockQuery.data])

  const toPayload = (values: OpeningStockEditorValues): OpeningStockFormValues => ({
    warehouse_id: values.warehouse_id,
    cutoff_date: values.cutoff_date,
    remarks: values.remarks || null,
    items: values.items.map((line) => ({
      item_id: line.item_id,
      qty: parseLocaleQty(line.qty),
      unit_cost: parseLocaleQty(line.unitCost),
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: OpeningStockEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateOpeningStock(id!, payload) : createOpeningStock(payload)
    },
    onSuccess: (openingStock) => {
      queryClient.invalidateQueries({ queryKey: ['opening-stocks'] })
      toast.success(isEdit ? 'Opening Stock details updated.' : 'Opening Stock recorded. Submit to create the FIFO layers.')
      if (!isEdit) {
        navigate(`/inventory/opening-stock/${openingStock.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitOpeningStock(id!),
    onSuccess: (openingStock) => {
      queryClient.invalidateQueries({ queryKey: ['opening-stocks'] })
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Opening Stock submitted — layers created.')
      navigate(`/inventory/opening-stock/${openingStock.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  if (isEdit && openingStockQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${openingStockQuery.data?.document_number ?? 'Opening Stock'}` : 'New Opening Stock'}
        description="Record starting inventory balances and their cost — kept separate from purchase documents."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Opening Stock Details</CardTitle>
              <StatusBadge status={isEdit ? (openingStockQuery.data?.status ?? 'draft') : 'draft'} />
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
                name="cutoff_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Cutoff Date</FormLabel>
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
              <OpeningStockLineItemTable form={form} />
              {form.formState.errors.items?.message && (
                <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.message}</p>
              )}
            </CardContent>
          </Card>

          <p className="text-right text-sm text-muted-foreground">
            Recording quantities here doesn't move stock yet — Submit is what creates the FIFO layers.
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/inventory/opening-stock')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && openingStockQuery.data?.status === 'draft' && (
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
