import { useEffect, useMemo, useState } from 'react'
import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll, STICKY_FIRST_COL } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { searchCustomersLookup, searchSuppliersLookup } from '@/features/master/api/lookupsApi'
import type { JournalEntry } from '../types'
import type { JournalEntryEditorValues } from '../lib/journalEntryFormSchema'

interface JournalEntryLineItemTableProps {
  form: UseFormReturn<JournalEntryEditorValues>
  disabled?: boolean
  /** Edit mode only — seeds the Sub Ledger pickers' labels so they don't need a search round-trip to show what's already saved. */
  entry?: JournalEntry | null
}

const loadCustomerOptions = async (query: string) => {
  const customers = await searchCustomersLookup(query)
  return customers.map((customer) => ({
    value: customer.id,
    label: `${customer.customer_code} — ${customer.customer_name}`,
    data: { name: customer.customer_name },
  }))
}

const loadSupplierOptions = async (query: string) => {
  const suppliers = await searchSuppliersLookup(query)
  return suppliers.map((supplier) => ({
    value: supplier.id,
    label: `${supplier.supplier_code} — ${supplier.supplier_name}`,
    data: { name: supplier.supplier_name },
  }))
}

/** Same editable-grid pattern as SalesOrderLineItemTable — genuinely user-added/removed rows, unlike Invoice/Delivery's fixed-from-source lines. */
export function JournalEntryLineItemTable({ form, disabled, entry }: JournalEntryLineItemTableProps) {
  const { control, setValue, getValues } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'lines' })
  const accounts = useChartOfAccountsLookup()
  // A Group/header account (has children) carries no postings of its own — Chart of Accounts
  // enforces the same thing structurally, this just surfaces it before the user picks one.
  const accountOptions =
    accounts.data?.map((account) => {
      const isGroup = (account.children_count ?? 0) > 0
      return { value: account.id, label: `${account.code} — ${account.name}${isGroup ? ' (Group)' : ''}`, disabled: isGroup }
    }) ?? []
  const accountsById = useMemo(() => new Map((accounts.data ?? []).map((account) => [account.id, account])), [accounts.data])

  const watchedLines = useWatch({ control, name: 'lines' })

  // Customer/Supplier are async-searched (the master can run into the thousands — same reasoning
  // as SalesOrderEditorPage's own Customer field), so each row's selected label is tracked here,
  // keyed by useFieldArray's stable field.id rather than its array index.
  const [selectedCustomerOptions, setSelectedCustomerOptions] = useState<Record<string, SearchableSelectOption<{ name: string }>>>({})
  const [selectedSupplierOptions, setSelectedSupplierOptions] = useState<Record<string, SearchableSelectOption<{ name: string }>>>({})

  // Seeds the labels above from the loaded entry (edit mode) — fields[i] aligns with
  // entry.lines[i] because form.reset() (JournalEntryEditorPage) populates both in the same order.
  useEffect(() => {
    if (!entry) return

    const customerSeed: Record<string, SearchableSelectOption<{ name: string }>> = {}
    const supplierSeed: Record<string, SearchableSelectOption<{ name: string }>> = {}

    entry.lines.forEach((line, index) => {
      const fieldId = fields[index]?.id
      if (!fieldId) return
      if (line.customer) customerSeed[fieldId] = { value: line.customer.id, label: line.customer.name, data: { name: line.customer.name } }
      if (line.supplier) supplierSeed[fieldId] = { value: line.supplier.id, label: line.supplier.name, data: { name: line.supplier.name } }
    })

    setSelectedCustomerOptions(customerSeed)
    setSelectedSupplierOptions(supplierSeed)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entry])

  // Keeps each line's required_subledger — and clears a now-irrelevant customer/supplier pick —
  // in sync with the selected account's ChartOfAccount.subledger_type. Mirrors
  // deliveryLineRowSchema's snapshotted fields (ordered/remaining/availableStock): the zod schema
  // validates against this field instead of needing the async accounts lookup injected into it.
  const accountIdsKey = (watchedLines ?? []).map((line) => line?.chart_of_account_id ?? '').join('|')
  useEffect(() => {
    if (!accounts.data) return

    ;(watchedLines ?? []).forEach((line, index) => {
      const required = accountsById.get(line?.chart_of_account_id ?? '')?.subledger_type ?? null

      if (getValues(`lines.${index}.required_subledger`) !== required) {
        setValue(`lines.${index}.required_subledger`, required, { shouldValidate: false })
      }
      if (required !== 'customer' && getValues(`lines.${index}.customer_id`)) {
        setValue(`lines.${index}.customer_id`, '')
      }
      if (required !== 'vendor' && getValues(`lines.${index}.supplier_id`)) {
        setValue(`lines.${index}.supplier_id`, '')
      }
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accountIdsKey, accounts.data])

  return (
    <div className="flex flex-col gap-3">
      <LineItemTableScroll>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={STICKY_FIRST_COL}>Chart of Account</TableHead>
              <TableHead className="w-56">Sub Ledger</TableHead>
              <TableHead className="w-36">Debit</TableHead>
              <TableHead className="w-36">Credit</TableHead>
              <TableHead>Description</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="p-0">
                  <EmptyState message="No lines yet." description="Use Add Line to start building this entry." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const requiredSubledger = watchedLines?.[index]?.required_subledger ?? null
                const selectedAccount = accountsById.get(watchedLines?.[index]?.chart_of_account_id ?? '')
                const lineErrors = form.formState.errors.lines?.[index]

                return (
                  <TableRow key={field.id}>
                    <TableCell className={STICKY_FIRST_COL}>
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
                    <TableCell className="min-w-56">
                      {requiredSubledger === 'customer' && (
                        <FormField
                          control={control}
                          name={`lines.${index}.customer_id`}
                          render={({ field: customerField }) => (
                            <FormItem className="gap-0">
                              <SearchableSelect
                                loadOptions={loadCustomerOptions}
                                selectedOption={selectedCustomerOptions[field.id]}
                                value={customerField.value}
                                onChange={(value, option) => {
                                  customerField.onChange(value ?? '')
                                  setSelectedCustomerOptions((prev) => ({ ...prev, [field.id]: option! }))
                                  if (option && !getValues(`lines.${index}.description`)) {
                                    const customerName = option.data?.name ?? option.label
                                    setValue(`lines.${index}.description`, selectedAccount ? `${selectedAccount.name}, ${customerName}` : customerName)
                                  }
                                }}
                                placeholder="Select customer"
                                disabled={disabled}
                                aria-label="Customer"
                              />
                              {!customerField.value && !lineErrors?.customer_id && (
                                <p className="text-xs text-muted-foreground">Pilih customer agar baris ini bisa dikaitkan ke piutang customer yang tepat.</p>
                              )}
                              <FormMessage />
                            </FormItem>
                          )}
                        />
                      )}
                      {requiredSubledger === 'vendor' && (
                        <FormField
                          control={control}
                          name={`lines.${index}.supplier_id`}
                          render={({ field: supplierField }) => (
                            <FormItem className="gap-0">
                              <SearchableSelect
                                loadOptions={loadSupplierOptions}
                                selectedOption={selectedSupplierOptions[field.id]}
                                value={supplierField.value}
                                onChange={(value, option) => {
                                  supplierField.onChange(value ?? '')
                                  setSelectedSupplierOptions((prev) => ({ ...prev, [field.id]: option! }))
                                  if (option && !getValues(`lines.${index}.description`)) {
                                    const supplierName = option.data?.name ?? option.label
                                    setValue(`lines.${index}.description`, selectedAccount ? `${selectedAccount.name}, ${supplierName}` : supplierName)
                                  }
                                }}
                                placeholder="Select vendor"
                                disabled={disabled}
                                aria-label="Vendor"
                              />
                              {!supplierField.value && !lineErrors?.supplier_id && (
                                <p className="text-xs text-muted-foreground">Pilih vendor agar baris ini bisa dikaitkan ke hutang vendor yang tepat.</p>
                              )}
                              <FormMessage />
                            </FormItem>
                          )}
                        />
                      )}
                      {!requiredSubledger && <span className="text-sm text-muted-foreground">—</span>}
                    </TableCell>
                    <TableCell className="min-w-36">
                      <FormField
                        control={control}
                        name={`lines.${index}.debit`}
                        render={({ field: debitField }) => (
                          <FormItem className="gap-0">
                            <RupiahInput value={debitField.value} onChange={debitField.onChange} disabled={disabled} decimals={2} />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="min-w-36">
                      <FormField
                        control={control}
                        name={`lines.${index}.credit`}
                        render={({ field: creditField }) => (
                          <FormItem className="gap-0">
                            <RupiahInput value={creditField.value} onChange={creditField.onChange} disabled={disabled} decimals={2} />
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
        onClick={() => append({ chart_of_account_id: '', required_subledger: null, customer_id: '', supplier_id: '', debit: '', credit: '', description: '' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Line
      </Button>
    </div>
  )
}
