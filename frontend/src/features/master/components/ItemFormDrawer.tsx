import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { toastApiError } from '@/shared/services/errorHandler'
import { createItem, updateItem } from '../api/itemApi'
import { fetchItemGroups, fetchUoms, fetchTaxesLookup } from '../api/lookupsApi'
import type { Item } from '../types'

const NO_TAX = '__none__'

const itemFormSchema = z.object({
  item_code: z.string().min(1, 'Item Code is required').max(255),
  item_name: z.string().min(1, 'Item Name is required').max(255),
  item_group_id: z.string().min(1, 'Item Group is required'),
  uom_id: z.string().min(1, 'UOM is required'),
  standard_rate: z
    .string()
    .min(1, 'Standard Rate is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
  purchase_tax_id: z.string(),
  sales_tax_id: z.string(),
  allow_over_receipt: z.boolean(),
})

type ItemFormSchemaValues = z.infer<typeof itemFormSchema>

const emptyValues: ItemFormSchemaValues = {
  item_code: '',
  item_name: '',
  item_group_id: '',
  uom_id: '',
  standard_rate: '0',
  purchase_tax_id: '',
  sales_tax_id: '',
  allow_over_receipt: false,
}

interface ItemFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  item?: Item | null
}

/** Right-side Drawer shared by Create and Edit — the reference pattern for every module's form going forward. */
export function ItemFormDrawer({ open, onOpenChange, item }: ItemFormDrawerProps) {
  const isEdit = !!item
  const queryClient = useQueryClient()

  const form = useForm<ItemFormSchemaValues>({
    resolver: zodResolver(itemFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      item
        ? {
            item_code: item.item_code,
            item_name: item.item_name,
            item_group_id: item.item_group_id,
            uom_id: item.uom_id,
            standard_rate: String(item.standard_rate),
            purchase_tax_id: item.purchase_tax_id ?? '',
            sales_tax_id: item.sales_tax_id ?? '',
            allow_over_receipt: item.allow_over_receipt,
          }
        : emptyValues,
    )
  }, [open, item, form])

  const itemGroups = useQuery({ queryKey: ['item-groups'], queryFn: fetchItemGroups })
  const uoms = useQuery({ queryKey: ['uoms'], queryFn: fetchUoms })
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })
  const activeTaxes = (taxesQuery.data ?? []).filter((t) => t.is_active)
  const purchaseTaxOptions = activeTaxes.filter((t) => t.transaction_type === 'purchase')
  const salesTaxOptions = activeTaxes.filter((t) => t.transaction_type === 'sales')

  const mutation = useMutation({
    mutationFn: (values: ItemFormSchemaValues) => {
      const payload = {
        ...values,
        standard_rate: Number(values.standard_rate),
        purchase_tax_id: values.purchase_tax_id || null,
        sales_tax_id: values.sales_tax_id || null,
      }
      return isEdit ? updateItem(item.id, payload) : createItem(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['items'] })
      toast.success(isEdit ? 'Item updated.' : 'Item created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: ItemFormSchemaValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Item' : 'New Item'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${item.item_code}.` : 'Add a new item to the catalog.'}
          </SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="item_code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Item Code</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. ITM001" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="item_name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Item Name</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Semen Portland 50kg" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="item_group_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Item Group</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={itemGroups.isLoading ? 'Loading…' : 'Select item group'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {itemGroups.data?.map((group) => (
                          <SelectItem key={group.id} value={group.id}>
                            {group.name}
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
                name="uom_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>UOM</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={uoms.isLoading ? 'Loading…' : 'Select unit of measurement'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {uoms.data?.map((uom) => (
                          <SelectItem key={uom.id} value={uom.id}>
                            {uom.name}
                            {uom.symbol ? ` (${uom.symbol})` : ''}
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
                name="standard_rate"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Standard Rate</FormLabel>
                    <FormControl>
                      <Input type="number" min={0} step="0.01" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="allow_over_receipt"
                render={({ field }) => (
                  <FormItem className="flex flex-row items-center gap-2 space-y-0">
                    <FormControl>
                      <Checkbox checked={field.value} onCheckedChange={(checked) => field.onChange(checked === true)} />
                    </FormControl>
                    <FormLabel className="font-normal">
                      Allow Over-Receipt (Goods Receipt qty may exceed the Purchase Order)
                    </FormLabel>
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="purchase_tax_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Purchase Tax</FormLabel>
                    <Select value={field.value || NO_TAX} onValueChange={(value) => field.onChange(value === NO_TAX ? '' : value)}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={taxesQuery.isLoading ? 'Loading…' : 'No tax'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_TAX}>No tax</SelectItem>
                        {purchaseTaxOptions.map((t) => (
                          <SelectItem key={t.id} value={t.id}>
                            {t.name} ({t.code})
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
                name="sales_tax_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Sales Tax</FormLabel>
                    <Select value={field.value || NO_TAX} onValueChange={(value) => field.onChange(value === NO_TAX ? '' : value)}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={taxesQuery.isLoading ? 'Loading…' : 'No tax'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_TAX}>No tax</SelectItem>
                        {salesTaxOptions.map((t) => (
                          <SelectItem key={t.id} value={t.id}>
                            {t.name} ({t.code})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create Item'}
              </Button>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
                Cancel
              </Button>
            </SheetFooter>
          </form>
        </Form>
      </SheetContent>
    </Sheet>
  )
}
