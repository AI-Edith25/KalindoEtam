import { useEffect } from 'react'
import { useFieldArray, useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Loader2, Plus, Trash2 } from 'lucide-react'
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
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { createItem, updateItem } from '../api/itemApi'
import { fetchItemGroups, fetchUoms, fetchTaxesLookup } from '../api/lookupsApi'
import type { Item } from '../types'

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
  qty_category: z.enum(['unit', 'weight']),
  // Extra UOMs (the base is uom_id) — factor = how many base units one of this UOM holds.
  uoms: z.array(
    z.object({
      uom_id: z.string().min(1, 'UOM is required'),
      conversion_factor: z
        .string()
        .min(1, 'Factor is required')
        .refine((value) => !Number.isNaN(Number(value)) && Number(value) > 0, 'Must be greater than zero'),
    }),
  ),
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
  qty_category: 'unit',
  uoms: [],
}

interface ItemFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  item?: Item | null
  /** True when the item's price differs by warehouse — Standard Rate is then read-only here, see warehousePriceSummary. */
  priceVaries?: boolean
  warehousePriceSummary?: { warehouseCode: string; rate: number }[]
}

/** Right-side Drawer shared by Create and Edit — the reference pattern for every module's form going forward. */
export function ItemFormDrawer({ open, onOpenChange, item, priceVaries = false, warehousePriceSummary = [] }: ItemFormDrawerProps) {
  const isEdit = !!item
  const queryClient = useQueryClient()
  const navigate = useNavigate()

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
            qty_category: item.qty_category,
            uoms: (item.uoms ?? [])
              .filter((choice) => !choice.is_base)
              .map((choice) => ({ uom_id: choice.uom_id, conversion_factor: String(Number(choice.conversion_factor)) })),
          }
        : emptyValues,
    )
  }, [open, item, form])

  const { fields: uomFields, append: appendUom, remove: removeUom } = useFieldArray({ control: form.control, name: 'uoms' })
  const watchedUoms = useWatch({ control: form.control, name: 'uoms' })
  const baseUomId = useWatch({ control: form.control, name: 'uom_id' })

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
        uoms: values.uoms.map((row) => ({ uom_id: row.uom_id, conversion_factor: Number(row.conversion_factor) })),
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
      <SheetContent className="w-full sm:max-w-lg">
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
                    <SearchableSelect
                      options={itemGroups.data?.map((group) => ({ value: group.id, label: group.name })) ?? []}
                      value={field.value}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={itemGroups.isLoading}
                      clearable={false}
                      placeholder="Select item group"
                      aria-label="Item Group"
                    />
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
                    <SearchableSelect
                      options={uoms.data?.map((uom) => ({ value: uom.id, label: `${uom.name}${uom.symbol ? ` (${uom.symbol})` : ''}` })) ?? []}
                      value={field.value}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={uoms.isLoading}
                      clearable={false}
                      placeholder="Select unit of measurement"
                      aria-label="UOM"
                    />
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div className="flex flex-col gap-2">
                <FormLabel>UOM Tambahan</FormLabel>
                <p className="text-xs text-muted-foreground">
                  Satuan lain untuk beli/jual item ini (mis. DUS). Faktor = berapa UOM dasar dalam 1 satuan tambahan.
                </p>
                {uomFields.map((uomField, index) => (
                  <div key={uomField.id} className="flex items-start gap-2">
                    <FormField
                      control={form.control}
                      name={`uoms.${index}.uom_id`}
                      render={({ field }) => (
                        <FormItem className="flex-1">
                          <SearchableSelect
                            options={(uoms.data ?? [])
                              .filter((uom) => uom.id !== baseUomId && !(watchedUoms ?? []).some((row, i) => i !== index && row.uom_id === uom.id))
                              .map((uom) => ({ value: uom.id, label: `${uom.name}${uom.symbol ? ` (${uom.symbol})` : ''}` }))}
                            value={field.value || undefined}
                            onChange={(value) => field.onChange(value ?? '')}
                            loading={uoms.isLoading}
                            clearable={false}
                            placeholder="Pilih UOM"
                            aria-label="UOM Tambahan"
                          />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name={`uoms.${index}.conversion_factor`}
                      render={({ field }) => (
                        <FormItem className="w-40">
                          <div className="flex items-center gap-1.5">
                            <Input type="number" min={0} step="any" placeholder="Faktor" {...field} />
                            <span className="whitespace-nowrap text-xs text-muted-foreground">
                              {uoms.data?.find((uom) => uom.id === baseUomId)?.name ?? 'dasar'}
                            </span>
                          </div>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="size-9 text-destructive hover:text-destructive"
                      onClick={() => removeUom(index)}
                    >
                      <Trash2 className="size-4" />
                      <span className="sr-only">Hapus UOM</span>
                    </Button>
                  </div>
                ))}
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="self-start"
                  onClick={() => appendUom({ uom_id: '', conversion_factor: '' })}
                  disabled={!baseUomId}
                >
                  <Plus className="size-4" />
                  Tambah UOM
                </Button>
              </div>
              <FormField
                control={form.control}
                name="qty_category"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Qty Category</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="unit">Unit</SelectItem>
                        <SelectItem value="weight">Weight</SelectItem>
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                      Kategori Unit → qty diinput bilangan bulat. Kategori Weight → qty boleh desimal.
                    </p>
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
                      <Input type="number" min={0} step="0.01" {...field} disabled={priceVaries} />
                    </FormControl>
                    {priceVaries ? (
                      <div className="rounded-md border bg-muted/50 p-2 text-xs text-muted-foreground">
                        <p>Harga item ini berbeda per lokasi. Kelola harga di halaman Item Prices.</p>
                        <p className="mt-1">
                          {warehousePriceSummary.map((w) => `${w.warehouseCode}: ${formatCurrency(w.rate)}`).join(', ')}
                        </p>
                        <Button
                          type="button"
                          variant="link"
                          className="h-auto p-0 text-xs"
                          onClick={() => item && navigate(`/master/item-prices?item_code=${encodeURIComponent(item.item_code)}`)}
                        >
                          Kelola harga per lokasi
                        </Button>
                      </div>
                    ) : (
                      <FormMessage />
                    )}
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
                    <SearchableSelect
                      options={purchaseTaxOptions.map((t) => ({ value: t.id, label: `${t.name} (${t.code})` }))}
                      value={field.value || undefined}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={taxesQuery.isLoading}
                      placeholder="No tax"
                      aria-label="Purchase Tax"
                    />
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
                    <SearchableSelect
                      options={salesTaxOptions.map((t) => ({ value: t.id, label: `${t.name} (${t.code})` }))}
                      value={field.value || undefined}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={taxesQuery.isLoading}
                      placeholder="No tax"
                      aria-label="Sales Tax"
                    />
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
