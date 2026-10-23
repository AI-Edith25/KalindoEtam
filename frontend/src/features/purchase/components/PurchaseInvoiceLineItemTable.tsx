import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll, STICKY_FIRST_COL } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { formatCurrency } from '@/lib/utils'
import { formatQty } from '@/shared/lib/qty'
import { isLiabilityLine, purchaseInvoiceLineAmount } from '../lib/directLineAmount'
import { isHeaderAccount } from '@/features/master/lib/chartOfAccountOptions'
import type { PurchaseInvoiceEditorValues } from '../lib/purchaseInvoiceFormSchema'
import type { ChartOfAccount, Tax } from '@/features/master/types'

const NO_TAX = '__none__'

interface PurchaseInvoiceLineItemTableProps {
  form: UseFormReturn<PurchaseInvoiceEditorValues>
  accounts: ChartOfAccount[]
  accountsLoading?: boolean
  taxes: Tax[]
}

/**
 * Unified Purchase Invoice line items table — renders Goods Receipt-sourced ("From GR") rows
 * locked (Item/Qty read-only, Rate editable) alongside manually added ("Manual") non-stock rows
 * (Account/Description/Qty/UOM/Rate/Tax). "+ Add Non-Stock Line" is always available regardless
 * of whether any stock rows are present — stock rows themselves have no Add/Remove, the set is
 * fixed by which Goods Receipt(s) were selected before reaching this screen.
 */
export function PurchaseInvoiceLineItemTable({ form, accounts, accountsLoading, taxes }: PurchaseInvoiceLineItemTableProps) {
  const { control } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' }) ?? []

  const accountOptions = accounts.map((account) => ({
    value: account.id,
    label: account.account_type === 'liability' ? `${account.code} — ${account.name} (Liability)` : `${account.code} — ${account.name}`,
    disabled: isHeaderAccount(account),
  }))

  return (
    <div className="flex flex-col gap-3">
      <LineItemTableScroll>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10" />
              <TableHead className={STICKY_FIRST_COL}>Item / Account</TableHead>
              <TableHead className="w-56">Description</TableHead>
              <TableHead className="w-24 text-right">Qty</TableHead>
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
                <TableCell colSpan={9} className="p-0">
                  <EmptyState message="No line items yet." description="Select a Goods Receipt or use Add Non-Stock Line to start building this invoice." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const line = watchedItems[index]

                if (!line || line.kind === 'stock') {
                  return (
                    <TableRow key={field.id}>
                      <TableCell>
                        <Badge variant="secondary">From GR</Badge>
                      </TableCell>
                      <TableCell className={STICKY_FIRST_COL}>{line?.item_code ?? '—'}</TableCell>
                      <TableCell>{line?.item_name ?? ''}</TableCell>
                      <TableCell className="text-right">{formatQty(line?.qty ?? 0, line?.qty_category ?? 'unit')}</TableCell>
                      <TableCell>{line?.uom ?? '—'}</TableCell>
                      <TableCell className="min-w-36">
                        <FormField
                          control={control}
                          name={`items.${index}.rate`}
                          render={({ field: rateField }) => (
                            <FormItem className="gap-0">
                              <RupiahInput value={rateField.value} onChange={rateField.onChange} decimals={2} aria-label="Rate" />
                              <FormMessage />
                            </FormItem>
                          )}
                        />
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">—</TableCell>
                      <TableCell className="text-right font-medium">{line ? formatCurrency(purchaseInvoiceLineAmount(line, accounts)) : '—'}</TableCell>
                      <TableCell />
                    </TableRow>
                  )
                }

                const isLiability = isLiabilityLine(line, accounts)

                return (
                  <TableRow key={field.id}>
                    <TableCell>
                      <Badge variant="outline">Manual</Badge>
                    </TableCell>
                    <TableCell className={STICKY_FIRST_COL}>
                      <FormField
                        control={control}
                        name={`items.${index}.chart_of_account_id`}
                        render={({ field: accountField }) => (
                          <FormItem className="gap-0">
                            <SearchableSelect
                              options={accountOptions}
                              value={accountField.value}
                              onChange={(value) => {
                                accountField.onChange(value ?? '')
                                if (accounts.find((account) => account.id === value)?.account_type === 'liability') {
                                  form.setValue(`items.${index}.tax_id`, '')
                                }
                              }}
                              loading={accountsLoading}
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
                            <Input placeholder="Description" {...descriptionField} />
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
                            <Input type="number" min={0.01} step="0.01" {...qtyField} />
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
                            <Input placeholder="Optional" {...uomField} value={uomField.value ?? ''} />
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
                            <RupiahInput value={rateField.value} onChange={rateField.onChange} decimals={2} />
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
                              value={isLiability ? NO_TAX : taxField.value || NO_TAX}
                              onValueChange={(value) => taxField.onChange(value === NO_TAX ? '' : value)}
                              disabled={isLiability}
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
                    <TableCell className="text-right font-medium">{formatCurrency(purchaseInvoiceLineAmount(line, accounts))}</TableCell>
                    <TableCell>
                      <Button type="button" variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" onClick={() => remove(index)}>
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
        onClick={() => append({ kind: 'non_stock', chart_of_account_id: '', description: '', uom: '', qty: '1', rate: '0', tax_id: '' })}
      >
        <Plus className="size-4" />
        Add Non-Stock Line
      </Button>
    </div>
  )
}
