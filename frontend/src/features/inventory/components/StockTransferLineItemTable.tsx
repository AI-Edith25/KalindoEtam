import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll } from '@/components/shared/LineItemTableScroll'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { formatQty, qtyDecimalPlaces } from '@/shared/lib/qty'
import { searchItemsLookup } from '@/features/master/api/lookupsApi'
import type { StockTransferEditorValues } from '../lib/stockTransferFormSchema'
import type { Item } from '@/features/master/types'

function itemLabel(item: Pick<Item, 'item_code' | 'item_name'>) {
  return `${item.item_code} — ${item.item_name}`
}

interface StockTransferLineItemTableProps {
  form: UseFormReturn<StockTransferEditorValues>
  disabled?: boolean
}

/**
 * Free-form field array (Add/Remove Row) — meniru StockAdjustmentLineItemTable.
 * Available Qty dibaca via useWatch, dipatch async dari bulk stock-balance
 * query gudang asal (lihat StockTransferEditorPage) sekali resolve — bukan
 * dari `fields` snapshot, sama kelas isu yang sudah ditangani di
 * DeliveryLineItemTable/StockAdjustmentLineItemTable.
 */
export function StockTransferLineItemTable({ form, disabled }: StockTransferLineItemTableProps) {
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
    if (selected) {
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
              <TableHead className="text-right">Available Qty</TableHead>
              <TableHead className="w-32 text-right">Qty to Transfer</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start recording a transfer." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const row = watchedItems?.[index]
                const availableQty = row?.availableQty ?? field.availableQty
                const qtyCategory = row?.qtyCategory ?? 'unit'
                const decimalPlaces = qtyDecimalPlaces(qtyCategory)
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
                    <TableCell className="text-right tabular-nums text-muted-foreground">{formatQty(availableQty, qtyCategory)}</TableCell>
                    <TableCell className="min-w-32">
                      <FormField
                        control={control}
                        name={`items.${index}.qty`}
                        render={({ field: qtyField }) => (
                          <FormItem className="gap-0">
                            <Input
                              type="number"
                              min={decimalPlaces > 0 ? 0.01 : 1}
                              step={decimalPlaces > 0 ? (10 ** -decimalPlaces).toFixed(decimalPlaces) : '1'}
                              disabled={disabled}
                              {...qtyField}
                            />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
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
        onClick={() => append({ item_id: '', item_code: '', item_name: '', qtyCategory: 'unit', availableQty: 0, qty: '1' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
