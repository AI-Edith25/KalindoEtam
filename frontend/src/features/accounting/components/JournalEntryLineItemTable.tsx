import { useFieldArray, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import type { JournalEntryEditorValues } from '../lib/journalEntryFormSchema'

interface JournalEntryLineItemTableProps {
  form: UseFormReturn<JournalEntryEditorValues>
  disabled?: boolean
}

/** Same editable-grid pattern as SalesOrderLineItemTable — genuinely user-added/removed rows, unlike Invoice/Delivery's fixed-from-source lines. */
export function JournalEntryLineItemTable({ form, disabled }: JournalEntryLineItemTableProps) {
  const { control } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'lines' })
  const accounts = useChartOfAccountsLookup()
  const accountOptions = accounts.data?.map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` })) ?? []

  return (
    <div className="flex flex-col gap-3">
      <LineItemTableScroll>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="sticky left-0 z-10 bg-background">Chart of Account</TableHead>
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
                  <TableCell className="sticky left-0 z-10 bg-background">
                    <FormField
                      control={control}
                      name={`lines.${index}.chart_of_account_id`}
                      render={({ field: accountField }) => (
                        <FormItem className="gap-0">
                          <SearchableSelect
                            options={accountOptions}
                            value={accountField.value}
                            onChange={(value) => accountField.onChange(value ?? '')}
                            loading={accounts.isLoading}
                            disabled={disabled}
                            placeholder="Select account"
                          />
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
                          <RupiahInput value={debitField.value} onChange={debitField.onChange} disabled={disabled} />
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
                          <RupiahInput value={creditField.value} onChange={creditField.onChange} disabled={disabled} />
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
      </LineItemTableScroll>

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
