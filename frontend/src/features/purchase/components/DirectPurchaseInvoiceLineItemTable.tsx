import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll, STICKY_FIRST_COL } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { formatCurrency } from '@/lib/utils'
import { lineAmount } from '@/shared/lib/documentTotals'
import type { DirectPurchaseInvoiceEditorValues } from '../lib/purchaseInvoiceFormSchema'
import type { ChartOfAccount, Tax } from '@/features/master/types'

const NO_TAX = '__none__'

interface DirectPurchaseInvoiceLineItemTableProps {
  form: UseFormReturn<DirectPurchaseInvoiceEditorValues>
  accounts: ChartOfAccount[]
  accountsLoading?: boolean
  taxes: Tax[]
  disabled?: boolean
}

/**
 * Direct/Non-Stock Purchase Invoice — no Item, no Goods Receipt. Each line picks a
 * Chart-of-Accounts expense account instead of an Item (structural sibling of
 * DirectGoodsReceiptLineItemTable, picker cell swapped for an account picker), types its own
 * free-text Description/UOM, and its own optional Tax — unlike a Goods Receipt-sourced invoice
 * (one manual header tax_amount), each Direct line has its own tax, summed server-side.
 */
export function DirectPurchaseInvoiceLineItemTable({ form, accounts, accountsLoading, taxes, disabled }: DirectPurchaseInvoiceLineItemTableProps) {
  const { control } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const accountOptions = accounts.map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))

  return (
    <div className="flex flex-col gap-3">
      <LineItemTableScroll>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={STICKY_FIRST_COL}>Account</TableHead>
              <TableHead className="w-56">Description</TableHead>
              <TableHead className="w-24">Qty</TableHead>
              <TableHead className="w-24">UOM</TableHead>
              <TableHead className="w-36">Rate</TableHead>
              <TableHead className="w-44">Tax</TableHead>
              <TableHead className="w-36 text-right">Amount</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={8} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start building this invoice." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => (
                <TableRow key={field.id}>
                  <TableCell className={STICKY_FIRST_COL}>
                    <FormField
                      control={control}
                      name={`items.${index}.chart_of_account_id`}
                      render={({ field: accountField }) => (
                        <FormItem className="gap-0">
                          <SearchableSelect
                            options={accountOptions}
                            value={accountField.value}
                            onChange={(value) => accountField.onChange(value ?? '')}
                            loading={accountsLoading}
                            disabled={disabled}
                            placeholder="Select account"
                            aria-label="Expense Account"
                          />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell className="min-w-56">
                    <FormField
                      control={control}
                      name={`items.${index}.description`}
                      render={({ field: descriptionField }) => (
                        <FormItem className="gap-0">
                          <Input placeholder="Description" disabled={disabled} {...descriptionField} />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell className="min-w-24">
                    <FormField
                      control={control}
                      name={`items.${index}.qty`}
                      render={({ field: qtyField }) => (
                        <FormItem className="gap-0">
                          <Input type="number" min={0.01} step="0.01" disabled={disabled} {...qtyField} />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell className="min-w-24">
                    <FormField
                      control={control}
                      name={`items.${index}.uom`}
                      render={({ field: uomField }) => (
                        <FormItem className="gap-0">
                          <Input placeholder="Optional" disabled={disabled} {...uomField} />
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
              ))
            )}
          </TableBody>
        </Table>
      </LineItemTableScroll>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => append({ chart_of_account_id: '', description: '', uom: '', qty: '1', rate: '0', tax_id: '' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
