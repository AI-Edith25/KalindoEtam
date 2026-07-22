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
import { toastApiError } from '@/shared/services/errorHandler'
import { createChartOfAccount, updateChartOfAccount } from '../api/chartOfAccountApi'
import type { ChartOfAccount } from '../types'

const chartOfAccountFormSchema = z.object({
  code: z.string().min(1, 'Code is required').max(20),
  name: z.string().min(1, 'Name is required').max(255),
  account_type: z.enum(['asset', 'liability', 'equity', 'revenue', 'expense']),
  is_active: z.boolean(),
})

type ChartOfAccountFormValues = z.infer<typeof chartOfAccountFormSchema>

const emptyValues: ChartOfAccountFormValues = {
  code: '',
  name: '',
  account_type: 'asset',
  is_active: true,
}

interface ChartOfAccountFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  chartOfAccount?: ChartOfAccount | null
}

export function ChartOfAccountFormDrawer({ open, onOpenChange, chartOfAccount }: ChartOfAccountFormDrawerProps) {
  const isEdit = !!chartOfAccount
  const queryClient = useQueryClient()

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
          }
        : emptyValues,
    )
  }, [open, chartOfAccount, form])

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
