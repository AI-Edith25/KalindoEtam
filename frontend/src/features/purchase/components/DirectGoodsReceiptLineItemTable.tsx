import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { formatCurrency } from '@/lib/utils'
import { lineAmount } from '@/shared/lib/documentTotals'
import { qtyDecimalPlaces } from '@/shared/lib/qty'
import { searchItemsLookup } from '@/features/master/api/lookupsApi'
import type { DirectGoodsReceiptEditorValues } from '../lib/goodsReceiptFormSchema'
import type { Item } from '@/features/master/types'

function itemLabel(item: Pick<Item, 'item_code' | 'item_name'>) {
  return `${item.item_code} — ${item.item_name}`
}

interface DirectGoodsReceiptLineItemTableProps {
  form: UseFormReturn<DirectGoodsReceiptEditorValues>
  disabled?: boolean
}

/**
 * Standalone/direct receipt (no source Purchase Order) — Add/Remove row,
 * item lookup autofilling Unit Price from standard_rate. Mirrors
 * PurchaseOrderLineItemTable minus the Tax column (GoodsReceiptItem
 * carries no tax fields).
 */
export function DirectGoodsReceiptLineItemTable({ form, disabled }: DirectGoodsReceiptLineItemTableProps) {
  const { control, setValue } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: itemLabel(item), data: item }))
  }

  const handleItemChange = (index: number, itemId: string, option?: SearchableSelectOption<Item>) => {
    setValue(`items.${index}.item_id`, itemId, { shouldValidate: true })

    const selected = option?.data
    setValue(`items.${index}.item_code`, selected?.item_code ?? '')
    setValue(`items.${index}.item_name`, selected?.item_name ?? '')
    setValue(`items.${index}.item_uom`, selected?.uom ? `${selected.uom.name}${selected.uom.symbol ? ` (${selected.uom.symbol})` : ''}` : '')
    if (selected) {
      setValue(`items.${index}.rate`, String(selected.standard_rate), { shouldValidate: true })
      setValue(`items.${index}.qtyCategory`, selected.qty_category, { shouldValidate: true })
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
              <TableHead className="w-36">Rate</TableHead>
              <TableHead className="w-36 text-right">Amount</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start building this receipt." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const row = watchedItems?.[index]
                const qtyCategory = row?.qtyCategory ?? 'unit'
                const decimalPlaces = qtyDecimalPlaces(qtyCategory)
                const uom = row?.item_uom || null
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
                          <div className="flex items-center gap-1.5">
                            <Input
                              type="number"
                              min={decimalPlaces > 0 ? 0.01 : 1}
                              step={decimalPlaces > 0 ? (10 ** -decimalPlaces).toFixed(decimalPlaces) : '1'}
                              disabled={disabled}
                              {...qtyField}
                            />
                            {uom && <span className="text-xs text-muted-foreground">{uom}</span>}
                          </div>
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
                  <TableCell className="text-right font-medium">
                    {formatCurrency(lineAmount(watchedItems?.[index] ?? { qty: 0, rate: 0 }))}
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
        onClick={() => append({ item_id: '', item_code: '', item_name: '', qtyCategory: 'unit', qty: '1', rate: '0' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
