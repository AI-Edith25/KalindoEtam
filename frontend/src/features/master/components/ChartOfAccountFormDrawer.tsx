import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { createChartOfAccount, updateChartOfAccount } from '../api/chartOfAccountApi'
import type { ChartOfAccount } from '../types'

const UNCLASSIFIED = '__unclassified__'
const NO_PARENT = '__no_parent__'

const chartOfAccountFormSchema = z.object({
  code: z.string().min(1, 'Code is required').max(20),
  name: z.string().min(1, 'Name is required').max(255),
  account_type: z.enum(['asset', 'liability', 'equity', 'revenue', 'expense']),
  is_active: z.boolean(),
  is_cash_bank: z.boolean(),
  cash_bank_category: z.enum(['petty_cash', 'cash_book']).nullable(),
  parent_id: z.string().nullable(),
})

type ChartOfAccountFormValues = z.infer<typeof chartOfAccountFormSchema>

const emptyValues: ChartOfAccountFormValues = {
  code: '',
  name: '',
  account_type: 'asset',
  is_active: true,
  is_cash_bank: false,
  cash_bank_category: null,
  parent_id: null,
}

interface ChartOfAccountFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  chartOfAccount?: ChartOfAccount | null
}

export function ChartOfAccountFormDrawer({ open, onOpenChange, chartOfAccount }: ChartOfAccountFormDrawerProps) {
  const isEdit = !!chartOfAccount
  const queryClient = useQueryClient()
  const accounts = useChartOfAccountsLookup()

  const form = useForm<ChartOfAccountFormValues>({
    resolver: zodResolver(chartOfAccountFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      chartOfAccount
        ? {
            code: chartOfAccount.code,
            name: chartOfAccount.name,
            account_type: chartOfAccount.account_type,
            is_active: chartOfAccount.is_active,
            is_cash_bank: chartOfAccount.is_cash_bank,
            cash_bank_category: chartOfAccount.cash_bank_category,
            parent_id: chartOfAccount.parent_id,
          }
        : emptyValues,
    )
  }, [open, chartOfAccount, form])

  // Two levels only: an account already carrying children can't also become a child (enforced
  // again server-side by UpdateChartOfAccountRequest) — hide the field rather than let the user
  // pick something the save will just reject.
  const canHaveParent = !chartOfAccount || !chartOfAccount.children_count
  const parentOptions = (accounts.data ?? [])
    .filter((account) => account.id !== chartOfAccount?.id && !account.parent_id)
    .map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))

  const mutation = useMutation({
    mutationFn: (values: ChartOfAccountFormValues) =>
      isEdit ? updateChartOfAccount(chartOfAccount.id, values) : createChartOfAccount(values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['chart-of-accounts-paged'] })
      toast.success(isEdit ? 'Chart of Account updated.' : 'Chart of Account created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: ChartOfAccountFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Account' : 'New Account'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${chartOfAccount.code}.` : 'Add a new chart of account.'}
          </SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Code</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. 1200" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Name</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Accounts Receivable" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="account_type"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Type</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Select type" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="asset">Asset</SelectItem>
                        <SelectItem value="liability">Liability</SelectItem>
                        <SelectItem value="equity">Equity</SelectItem>
                        <SelectItem value="revenue">Revenue</SelectItem>
                        <SelectItem value="expense">Expense</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {canHaveParent && (
                <FormField
                  control={form.control}
                  name="parent_id"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Parent Account</FormLabel>
                      <SearchableSelect
                        options={[{ value: NO_PARENT, label: 'No parent — top-level account' }, ...parentOptions]}
                        value={field.value ?? NO_PARENT}
                        onChange={(value) => field.onChange(!value || value === NO_PARENT ? null : value)}
                        loading={accounts.isLoading}
                        clearable={false}
                        placeholder="No parent — top-level account"
                        aria-label="Parent Account"
                      />
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}
              <FormField
                control={form.control}
                name="is_active"
                render={({ field }) => (
                  <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                    <FormLabel className="cursor-pointer">Active</FormLabel>
                    <FormControl>
                      <Switch checked={field.value} onCheckedChange={field.onChange} />
                    </FormControl>
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="is_cash_bank"
                render={({ field }) => (
                  <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
                    <div className="flex flex-col gap-0.5">
                      <FormLabel className="cursor-pointer">Cash / Bank Account</FormLabel>
                      <span className="text-xs text-muted-foreground">Appears as a Payment Method option on Incoming/Outgoing Payments.</span>
                    </div>
                    <FormControl>
                      <Switch checked={field.value} onCheckedChange={field.onChange} />
                    </FormControl>
                  </FormItem>
                )}
              />
              {form.watch('is_cash_bank') && (
                <FormField
                  control={form.control}
                  name="cash_bank_category"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Cash/Bank Category</FormLabel>
                      <Select
                        value={field.value ?? UNCLASSIFIED}
                        onValueChange={(value) => field.onChange(value === UNCLASSIFIED ? null : value)}
                      >
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue placeholder="Unclassified" />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          <SelectItem value={UNCLASSIFIED}>Unclassified</SelectItem>
                          <SelectItem value="petty_cash">Petty Cash</SelectItem>
                          <SelectItem value="cash_book">Cash Book</SelectItem>
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create Account'}
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
