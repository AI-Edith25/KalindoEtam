import { useEffect } from 'react'
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
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { parseLocaleQty } from '@/shared/lib/qty'
import { createIssueStock, fetchIssueStock, submitIssueStock, updateIssueStock } from '../api/issueStockApi'
import { IssueStockLineItemTable } from '../components/IssueStockLineItemTable'
import { emptyIssueStockEditorValues, issueStockFormSchema, type IssueStockEditorValues } from '../lib/issueStockFormSchema'
import type { IssueStockFormValues } from '../types'

export function IssueStockEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const issueStockQuery = useQuery({
    queryKey: ['issue-stocks', id],
    queryFn: () => fetchIssueStock(id!),
    enabled: isEdit,
  })

  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const warehouseOptions = warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []

  const form = useForm<IssueStockEditorValues>({
    resolver: zodResolver(issueStockFormSchema),
    defaultValues: emptyIssueStockEditorValues,
  })

  const warehouseId = useWatch({ control: form.control, name: 'warehouse_id' })

  useEffect(() => {
    const issueStock = issueStockQuery.data
    if (!issueStock) return

    if (issueStock.status !== 'draft') {
      toast.error('Only draft Issue Stock documents can be edited.')
      navigate(`/inventory/issue-stock/${issueStock.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [issueStockQuery.data])

  useEffect(() => {
    const issueStock = issueStockQuery.data
    if (!isEdit || !issueStock) return

    form.reset({
      warehouse_id: issueStock.warehouse_id,
      issue_date: issueStock.issue_date,
      remarks: issueStock.remarks ?? '',
      items: issueStock.items.map((line) => ({
        item_id: line.item_id,
        item_code: line.item_code,
        item_name: line.item_name,
        qtyCategory: line.qty_category,
        qty: String(line.qty),
      })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [issueStockQuery.data])

  const toPayload = (values: IssueStockEditorValues): IssueStockFormValues => ({
    warehouse_id: values.warehouse_id,
    issue_date: values.issue_date,
    remarks: values.remarks || null,
    items: values.items.map((line) => ({
      item_id: line.item_id,
      qty: parseLocaleQty(line.qty),
    })),
  })

  const saveMutation = useMutation({
    mutationFn: (values: IssueStockEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updateIssueStock(id!, payload) : createIssueStock(payload)
    },
    onSuccess: (issueStock) => {
      queryClient.invalidateQueries({ queryKey: ['issue-stocks'] })
      toast.success(isEdit ? 'Issue Stock details updated.' : 'Issue Stock recorded. Submit to consume the FIFO layers.')
      if (!isEdit) {
        navigate(`/inventory/issue-stock/${issueStock.id}/edit`, { replace: true })
      }
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitIssueStock(id!),
    onSuccess: (issueStock) => {
      queryClient.invalidateQueries({ queryKey: ['issue-stocks'] })
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Issue Stock submitted — FIFO layers consumed.')
      navigate(`/inventory/issue-stock/${issueStock.id}`)
    },
    onError: (error) => toastApiError(error),
  })

  if (isEdit && issueStockQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${issueStockQuery.data?.document_number ?? 'Issue Stock'}` : 'New Issue Stock'}
        description="Record stock leaving the warehouse outside of a sale — internal use, damage, samples."
      />

      <Form {...form}>
        <form onSubmit={form.handleSubmit((values) => saveMutation.mutate(values))} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Issue Stock Details</CardTitle>
              <StatusBadge status={isEdit ? (issueStockQuery.data?.status ?? 'draft') : 'draft'} />
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
                name="issue_date"
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
              <IssueStockLineItemTable form={form} warehouseId={warehouseId ?? ''} />
              {form.formState.errors.items?.message && (
                <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.message}</p>
              )}
            </CardContent>
          </Card>

          <p className="text-right text-sm text-muted-foreground">
            Recording quantities here doesn't move stock yet — Submit is what consumes the FIFO layers.
          </p>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/inventory/issue-stock')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && issueStockQuery.data?.status === 'draft' && (
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
