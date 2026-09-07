import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll } from '@/components/shared/LineItemTableScroll'
import { formatCurrency } from '@/lib/utils'
import { qtyDecimalPlaces } from '@/shared/lib/qty'
import { previewIssueStockCost } from '../api/issueStockApi'
import type { IssueStockEditorValues } from '../lib/issueStockFormSchema'
import type { Item } from '@/features/master/types'

interface IssueStockLineItemTableProps {
  form: UseFormReturn<IssueStockEditorValues>
  items: Item[]
  itemsLoading: boolean
  warehouseId: string
  disabled?: boolean
}

/**
 * Read-only FIFO-computed Unit Cost — the ticket's explicit requirement ("tampilkan Unit Cost
 * hasil FIFO, read-only, dihitung sistem"). Queries FifoLayerService::previewConsumption() (via
 * IssueStockController::previewCost()) per row as item/qty/warehouse change; never written back
 * into the form — the real cost is only ever decided by consume() at Submit time.
 */
function CostCell({ itemId, warehouseId, qty }: { itemId: string; warehouseId: string; qty: number }) {
  const enabled = !!itemId && !!warehouseId && qty > 0

  const preview = useQuery({
    queryKey: ['issue-stock-preview-cost', itemId, warehouseId, qty],
    queryFn: () => previewIssueStockCost(itemId, warehouseId, qty),
    enabled,
  })

  if (!enabled) return <span className="text-muted-foreground">—</span>
  if (preview.isLoading) return <span className="text-muted-foreground">…</span>
  if (!preview.data) return <span className="text-muted-foreground">—</span>

  const insufficient = qty > preview.data.available_qty
  return (
    <span className={insufficient ? 'text-destructive' : undefined}>
      {formatCurrency(preview.data.unit_cost)}
      {insufficient && <span className="block text-xs">Only {preview.data.available_qty} available</span>}
    </span>
  )
}

export function IssueStockLineItemTable({ form, items, itemsLoading, warehouseId, disabled }: IssueStockLineItemTableProps) {
  const { control, setValue } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const handleItemChange = (index: number, itemId: string) => {
    setValue(`items.${index}.item_id`, itemId, { shouldValidate: true })

    const selected = items.find((item) => item.id === itemId)
    if (selected) {
      setValue(`items.${index}.item_code`, selected.item_code)
      setValue(`items.${index}.item_name`, selected.item_name)
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
              <TableHead className="w-32 text-right">Qty</TableHead>
              <TableHead className="w-40 text-right">Unit Cost (FIFO)</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start recording issued stock." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const qtyCategory = watchedItems?.[index]?.qtyCategory ?? 'unit'
                const decimalPlaces = qtyDecimalPlaces(qtyCategory)
                const itemId = watchedItems?.[index]?.item_id ?? ''
                const qty = Number(watchedItems?.[index]?.qty?.replace(',', '.') || 0)

                return (
                  <TableRow key={field.id}>
                    <TableCell className="sticky left-0 z-10 bg-background">
                      <FormField
                        control={control}
                        name={`items.${index}.item_id`}
                        render={({ field: itemField }) => (
                          <FormItem className="gap-0">
                            <Select value={itemField.value} onValueChange={(value) => handleItemChange(index, value)} disabled={disabled}>
                              <SelectTrigger className="w-full">
                                <SelectValue placeholder={itemsLoading ? 'Loading…' : 'Select item'} />
                              </SelectTrigger>
                              <SelectContent>
                                {items.map((item) => (
                                  <SelectItem key={item.id} value={item.id}>
                                    {item.item_code} — {item.item_name}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="min-w-32">
                      <FormField
                        control={control}
                        name={`items.${index}.qty`}
                        render={({ field: qtyField }) => (
                          <FormItem className="gap-0">
                            <Input
                              type="number"
                              min={0}
                              step={decimalPlaces > 0 ? (10 ** -decimalPlaces).toFixed(decimalPlaces) : '1'}
                              disabled={disabled}
                              {...qtyField}
                            />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      <CostCell itemId={itemId} warehouseId={warehouseId} qty={qty} />
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
          {fields.length > 0 && (
            <TableFooter>
              <TableRow>
                <TableCell colSpan={2} className="text-right font-medium">
                  Total
                </TableCell>
                <TableCell colSpan={2} className="text-right text-xs text-muted-foreground">
                  Confirmed once Submitted
                </TableCell>
              </TableRow>
            </TableFooter>
          )}
        </Table>
      </LineItemTableScroll>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => append({ item_id: '', item_code: '', item_name: '', qtyCategory: 'unit', qty: '' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
