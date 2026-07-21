import { useFieldArray, type UseFormReturn } from 'react-hook-form'
import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { fetchChartOfAccountsLookup } from '@/features/master/api/lookupsApi'
import type { JournalEntryEditorValues } from '../lib/journalEntryFormSchema'

interface JournalEntryLineItemTableProps {
  form: UseFormReturn<JournalEntryEditorValues>
  disabled?: boolean
}

/** Same editable-grid pattern as SalesOrderLineItemTable — genuinely user-added/removed rows, unlike Invoice/Delivery's fixed-from-source lines. */
export function JournalEntryLineItemTable({ form, disabled }: JournalEntryLineItemTableProps) {
  const { control } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'lines' })
  const accounts = useQuery({ queryKey: ['chart-of-accounts-lookup'], queryFn: fetchChartOfAccountsLookup })

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Chart of Account</TableHead>
              <TableHead className="w-36">Debit</TableHead>
              <TableHead className="w-36">Credit</TableHead>
              <TableHead>Description</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="p-0">
                  <EmptyState message="No lines yet." description="Use Add Line to start building this entry." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => (
                <TableRow key={field.id}>
                  <TableCell>
                    <FormField
                      control={control}
                      name={`lines.${index}.chart_of_account_id`}
                      render={({ field: accountField }) => (
                        <FormItem className="gap-0">
                          <Select value={accountField.value} onValueChange={accountField.onChange} disabled={disabled}>
                            <SelectTrigger className="w-full">
                              <SelectValue placeholder={accounts.isLoading ? 'Loading…' : 'Select account'} />
                            </SelectTrigger>
                            <SelectContent>
                              {accounts.data?.map((account) => (
                                <SelectItem key={account.id} value={account.id}>
                                  {account.code} — {account.name}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell>
                    <FormField
                      control={control}
                      name={`lines.${index}.debit`}
                      render={({ field: debitField }) => (
                        <FormItem className="gap-0">
                          <Input type="number" min={0} step="0.01" placeholder="0" disabled={disabled} {...debitField} />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell>
                    <FormField
                      control={control}
                      name={`lines.${index}.credit`}
                      render={({ field: creditField }) => (
                        <FormItem className="gap-0">
                          <Input type="number" min={0} step="0.01" placeholder="0" disabled={disabled} {...creditField} />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell>
                    <FormField
                      control={control}
                      name={`lines.${index}.description`}
                      render={({ field: descriptionField }) => (
                        <FormItem className="gap-0">
                          <Input placeholder="Optional" disabled={disabled} {...descriptionField} />
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
                      <span className="sr-only">Remove line</span>
                    </Button>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => append({ chart_of_account_id: '', debit: '', credit: '', description: '' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Line
      </Button>
    </div>
  )
}
