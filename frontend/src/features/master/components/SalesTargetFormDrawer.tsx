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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { toastApiError } from '@/shared/services/errorHandler'
import { createSalesTarget, updateSalesTarget } from '../api/salesTargetApi'
import { MONTH_OPTIONS } from '../lib/months'
import type { SalesTarget } from '../types'

const NO_BRANCH = '__no_branch__'

// Numeric fields stay strings in the form itself (same convention as ItemFormDrawer's
// standard_rate) — react-hook-form's generic wants the schema's input and output shapes to
// match, which z.coerce.number() breaks; converting to a real number happens once, at submit.
const salesTargetFormSchema = z.object({
  sales_person_id: z.string().min(1, 'Sales person is required'),
  branch_id: z.string(),
  period_month: z.string().min(1),
  period_year: z
    .string()
    .min(1, 'Year is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 2000 && Number(value) <= 2100, 'Enter a valid year'),
  target_amount: z
    .string()
    .min(1, 'Target amount is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
})

type SalesTargetFormValues = z.infer<typeof salesTargetFormSchema>

const emptyValues: SalesTargetFormValues = {
  sales_person_id: '',
  branch_id: NO_BRANCH,
  period_month: String(new Date().getMonth() + 1),
  period_year: String(new Date().getFullYear()),
  target_amount: '0',
}

interface SalesTargetFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  salesTarget?: SalesTarget | null
  salesPersonOptions: { value: string; label: string }[]
  branchOptions: { value: string; label: string }[]
}

export function SalesTargetFormDrawer({ open, onOpenChange, salesTarget, salesPersonOptions, branchOptions }: SalesTargetFormDrawerProps) {
  const isEdit = !!salesTarget
  const queryClient = useQueryClient()

  const form = useForm<SalesTargetFormValues>({
    resolver: zodResolver(salesTargetFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      salesTarget
        ? {
            sales_person_id: salesTarget.sales_person_id,
            branch_id: salesTarget.branch_id ?? NO_BRANCH,
            period_month: String(salesTarget.period_month),
            period_year: String(salesTarget.period_year),
            target_amount: String(salesTarget.target_amount),
          }
        : emptyValues,
    )
  }, [open, salesTarget, form])

  const mutation = useMutation({
    mutationFn: (values: SalesTargetFormValues) => {
      const payload = {
        sales_person_id: values.sales_person_id,
        branch_id: values.branch_id === NO_BRANCH ? null : values.branch_id,
        period_month: Number(values.period_month),
        period_year: Number(values.period_year),
        target_amount: Number(values.target_amount),
      }
      return isEdit ? updateSalesTarget(salesTarget.id, payload) : createSalesTarget(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-targets'] })
      toast.success(isEdit ? 'Sales target updated.' : 'Sales target created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: SalesTargetFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Sales Target' : 'New Sales Target'}</SheetTitle>
          <SheetDescription>{isEdit ? 'Update this target.' : 'Set a sales target for a period.'}</SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="sales_person_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Sales Person</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Select a sales person" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {salesPersonOptions.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="branch_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Branch</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_BRANCH}>No branch (company-wide)</SelectItem>
                        {branchOptions.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div className="grid grid-cols-2 gap-3">
                <FormField
                  control={form.control}
                  name="period_month"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Month</FormLabel>
                      <Select value={field.value} onValueChange={field.onChange}>
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {MONTH_OPTIONS.map((month) => (
                            <SelectItem key={month.value} value={String(month.value)}>
                              {month.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="period_year"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Year</FormLabel>
                      <FormControl>
                        <Input type="number" autoComplete="off" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>
              <FormField
                control={form.control}
                name="target_amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Target Amount</FormLabel>
                    <FormControl>
                      <Input type="number" min={0} step="0.01" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create Sales Target'}
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
