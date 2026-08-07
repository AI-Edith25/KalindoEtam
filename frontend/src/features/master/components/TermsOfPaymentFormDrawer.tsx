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
import { toastApiError } from '@/shared/services/errorHandler'
import { createTermsOfPayment, updateTermsOfPayment } from '../api/termsOfPaymentApi'
import type { TermsOfPayment } from '../types'

const termsOfPaymentFormSchema = z.object({
  code: z.string().min(1, 'Code is required').max(255),
  name: z.string().min(1, 'Name is required').max(255),
  days: z
    .string()
    .min(1, 'Days is required')
    .refine((value) => Number.isInteger(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
  is_active: z.boolean(),
})

type TermsOfPaymentFormValues = z.infer<typeof termsOfPaymentFormSchema>

const emptyValues: TermsOfPaymentFormValues = {
  code: '',
  name: '',
  days: '',
  is_active: true,
}

interface TermsOfPaymentFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  termsOfPayment?: TermsOfPayment | null
}

export function TermsOfPaymentFormDrawer({ open, onOpenChange, termsOfPayment }: TermsOfPaymentFormDrawerProps) {
  const isEdit = !!termsOfPayment
  const queryClient = useQueryClient()

  const form = useForm<TermsOfPaymentFormValues>({
    resolver: zodResolver(termsOfPaymentFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      termsOfPayment
        ? {
            code: termsOfPayment.code,
            name: termsOfPayment.name,
            days: String(termsOfPayment.days),
            is_active: termsOfPayment.is_active,
          }
        : emptyValues,
    )
  }, [open, termsOfPayment, form])

  const mutation = useMutation({
    mutationFn: (values: TermsOfPaymentFormValues) => {
      const payload = { ...values, days: Number(values.days) }
      return isEdit ? updateTermsOfPayment(termsOfPayment.id, payload) : createTermsOfPayment(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['terms-of-payments'] })
      toast.success(isEdit ? 'Terms of payment updated.' : 'Terms of payment created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: TermsOfPaymentFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Terms of Payment' : 'New Terms of Payment'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${termsOfPayment.code}.` : 'Add a new payment term.'}
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
                      <Input placeholder="e.g. NET30" autoComplete="off" {...field} />
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
                      <Input placeholder="e.g. Net 30" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="days"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Days</FormLabel>
                    <FormControl>
                      <Input type="number" min="0" placeholder="e.g. 30 (0 for COD)" autoComplete="off" {...field} />
                    </FormControl>
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
                {isEdit ? 'Save Changes' : 'Create Terms of Payment'}
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
