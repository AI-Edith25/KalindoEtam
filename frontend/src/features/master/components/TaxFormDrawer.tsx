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
import { getErrorMessage } from '@/shared/services/errorHandler'
import { createTax, updateTax } from '../api/taxApi'
import type { Tax } from '../types'

const taxFormSchema = z.object({
  code: z.string().min(1, 'Code is required').max(50),
  name: z.string().min(1, 'Name is required').max(255),
  type: z.enum(['vat', 'zero_rated', 'exempt']),
  rate: z.string().refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0 && Number(value) <= 100, 'Must be between 0 and 100'),
  is_active: z.boolean(),
})

type TaxFormSchemaValues = z.infer<typeof taxFormSchema>

const emptyValues: TaxFormSchemaValues = {
  code: '',
  name: '',
  type: 'vat',
  rate: '0',
  is_active: true,
}

interface TaxFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  tax?: Tax | null
}

/** Rate is disabled and forced to 0 when Type isn't VAT — Zero Rated/Exempt always calculate to Rp 0 (TaxService::calculate()), so a stored rate on either would only be confusing. See docs/TAX_ENGINE_DESIGN.md §9. */
export function TaxFormDrawer({ open, onOpenChange, tax }: TaxFormDrawerProps) {
  const isEdit = !!tax
  const queryClient = useQueryClient()

  const form = useForm<TaxFormSchemaValues>({
    resolver: zodResolver(taxFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      tax
        ? { code: tax.code, name: tax.name, type: tax.type, rate: String(tax.rate), is_active: tax.is_active }
        : emptyValues,
    )
  }, [open, tax, form])

  const type = form.watch('type')
  const isVat = type === 'vat'

  useEffect(() => {
    if (!isVat) form.setValue('rate', '0')
  }, [isVat, form])

  const mutation = useMutation({
    mutationFn: (values: TaxFormSchemaValues) => {
      const payload = { ...values, rate: Number(values.rate) }
      return isEdit ? updateTax(tax.id, payload) : createTax(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['taxes-paged'] })
      toast.success(isEdit ? 'Tax updated.' : 'Tax created.')
      onOpenChange(false)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const onSubmit = (values: TaxFormSchemaValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Tax' : 'New Tax'}</SheetTitle>
          <SheetDescription>{isEdit ? `Update details for ${tax.code}.` : 'Add a new tax rate.'}</SheetDescription>
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
                      <Input placeholder="e.g. PPN11" autoComplete="off" {...field} />
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
                      <Input placeholder="e.g. PPN 11%" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="type"
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
                        <SelectItem value="vat">VAT</SelectItem>
                        <SelectItem value="zero_rated">Zero Rated</SelectItem>
                        <SelectItem value="exempt">Tax Exempt</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="rate"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Rate (%)</FormLabel>
                    <FormControl>
                      <Input type="number" min="0" max="100" step="0.01" disabled={!isVat} {...field} />
                    </FormControl>
                    {!isVat && <p className="text-xs text-muted-foreground">Zero Rated and Exempt always calculate to Rp 0.</p>}
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
                {isEdit ? 'Save Changes' : 'Create Tax'}
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
