import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { formatCurrency } from '@/lib/utils'
import { lineAmount, lineTaxAmount } from '@/shared/lib/documentTotals'
import { searchItemsLookup } from '@/features/master/api/lookupsApi'
import type { Item, Tax } from '@/features/master/types'
import type { SalesOrderEditorValues } from '../lib/salesOrderFormSchema'

const NO_TAX = '__none__'

function itemLabel(item: Pick<Item, 'item_code' | 'item_name'>) {
  return `${item.item_code} — ${item.item_name}`
}

interface SalesOrderLineItemTableProps {
  form: UseFormReturn<SalesOrderEditorValues>
  /** Reflects the order's selected Warehouse override — see SalesOrderEditorPage. */
  warehouseId?: string
  taxes: Tax[]
  disabled?: boolean
}

/**
 * Same editable-grid pattern as PurchaseOrderLineItemTable — Add/Remove
 * row, Item lookup autofilling Unit Price from effective_rate (the
 * order's Warehouse override, or standard_rate when there isn't one —
 * see fetchItemsLookup(warehouseId) in SalesOrderEditorPage)
 * and Tax from the Item's own sales_tax_id, live per-row Amount/Tax. Tax
 * and rate both stay editable per line afterward — the Item default is
 * only a starting point. No stock check on qty: Sales Order may exceed
 * current inventory (that validation belongs to Delivery, not here).
 */
export function SalesOrderLineItemTable({ form, warehouseId, taxes, disabled }: SalesOrderLineItemTableProps) {
  const { control, setValue } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query, warehouseId)
    return items.map((item) => ({ value: item.id, label: itemLabel(item), data: item }))
  }

  const handleItemChange = (index: number, itemId: string, option?: SearchableSelectOption<Item>) => {
    setValue(`items.${index}.item_id`, itemId, { shouldValidate: true })

    const selected = option?.data
    setValue(`items.${index}.item_code`, selected?.item_code ?? '')
    setValue(`items.${index}.item_name`, selected?.item_name ?? '')
    if (selected) {
      setValue(`items.${index}.rate`, String(selected.effective_rate), { shouldValidate: true })
      setValue(`items.${index}.tax_id`, selected.sales_tax_id ?? '', { shouldValidate: true })
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <LineItemTableScroll>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="sticky left-0 z-10 bg-background">Item</TableHead>
              <TableHead className="w-28">Qty</TableHead>
              <TableHead className="w-36">Unit Price</TableHead>
              <TableHead className="w-44">Tax</TableHead>
              <TableHead className="w-36 text-right">Amount</TableHead>
              <TableHead className="w-32 text-right">Tax Amount</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start building this order." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const row = watchedItems?.[index]
                const selectedOption: SearchableSelectOption<Item> | undefined =
                  row?.item_id && row.item_code ? { value: row.item_id, label: itemLabel({ item_code: row.item_code, item_name: row.item_name ?? '' }) } : undefined

                return (
                <TableRow key={field.id}>
                  <TableCell className="sticky left-0 z-10 bg-background">
                    <FormField
                      control={control}
                      name={`items.${index}.item_id`}
                      render={({ field: itemField }) => (
                        <FormItem className="gap-0">
                          <SearchableSelect
                            loadOptions={loadItemOptions}
                            selectedOption={selectedOption}
                            value={itemField.value}
                            onChange={(value, option) => handleItemChange(index, value ?? '', option)}
                            disabled={disabled}
                            placeholder="Select item"
                            aria-label="Item"
                          />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell className="min-w-28">
                    <FormField
                      control={control}
                      name={`items.${index}.qty`}
                      render={({ field: qtyField }) => (
                        <FormItem className="gap-0">
                          <Input type="number" min={1} step="1" disabled={disabled} {...qtyField} />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell className="min-w-36">
                    <FormField
                      control={control}
                      name={`items.${index}.rate`}
                      render={({ field: rateField }) => (
                        <FormItem className="gap-0">
                          <RupiahInput value={rateField.value} onChange={rateField.onChange} disabled={disabled} />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell>
                    <FormField
                      control={control}
                      name={`items.${index}.tax_id`}
                      render={({ field: taxField }) => (
                        <FormItem className="gap-0">
                          <Select
                            value={taxField.value || NO_TAX}
                            onValueChange={(value) => taxField.onChange(value === NO_TAX ? '' : value)}
                            disabled={disabled}
                          >
                            <SelectTrigger className="w-full">
                              <SelectValue placeholder="No tax" />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value={NO_TAX}>No tax</SelectItem>
                              {taxes.map((t) => (
                                <SelectItem key={t.id} value={t.id}>
                                  {t.name}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell className="text-right font-medium">
                    {formatCurrency(lineAmount(watchedItems?.[index] ?? { qty: 0, rate: 0 }))}
                  </TableCell>
                  <TableCell className="text-right text-muted-foreground">
                    {formatCurrency(
                      lineTaxAmount(
                        lineAmount(watchedItems?.[index] ?? { qty: 0, rate: 0 }),
                        taxes.find((t) => t.id === watchedItems?.[index]?.tax_id),
                      ),
                    )}
                  </TableCell>
                  <TableCell>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="size-8 text-destructive hover:text-destructive"
                      onClick={() => remove(index)}
                      disabled={disabled}
                    >
                      <Trash2 className="size-4" />
                      <span className="sr-only">Remove row</span>
                    </Button>
                  </TableCell>
                </TableRow>
                )
              })
            )}
          </TableBody>
        </Table>
      </LineItemTableScroll>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => append({ item_id: '', qty: '1', rate: '0', tax_id: '' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
