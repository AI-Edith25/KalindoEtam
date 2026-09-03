import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchTermsOfPaymentLookup } from '../api/lookupsApi'
import { createCustomer, updateCustomer } from '../api/customerApi'
import type { Customer } from '../types'

const NO_TOP = '__none__'

const customerFormSchema = z.object({
  customer_code: z.string().min(1, 'Customer Code is required').max(255),
  customer_name: z.string().min(1, 'Customer Name is required').max(255),
  phone: z.string().max(50).optional().or(z.literal('')),
  email: z.string().email('Enter a valid email address').optional().or(z.literal('')),
  address: z.string().max(255).optional().or(z.literal('')),
  credit_limit: z
    .string()
    .optional()
    .or(z.literal(''))
    .refine((value) => !value || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  terms_of_payment_id: z.string().optional().or(z.literal('')),
  is_active: z.boolean(),
})

type CustomerFormValues = z.infer<typeof customerFormSchema>

const emptyValues: CustomerFormValues = {
  customer_code: '',
  customer_name: '',
  phone: '',
  email: '',
  address: '',
  credit_limit: '',
  terms_of_payment_id: '',
  is_active: true,
}

interface CustomerFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  customer?: Customer | null
}

export function CustomerFormDrawer({ open, onOpenChange, customer }: CustomerFormDrawerProps) {
  const isEdit = !!customer
  const queryClient = useQueryClient()
  const termsOfPayment = useQuery({ queryKey: ['terms-of-payment-lookup'], queryFn: fetchTermsOfPaymentLookup })

  const form = useForm<CustomerFormValues>({
    resolver: zodResolver(customerFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      customer
        ? {
            customer_code: customer.customer_code,
            customer_name: customer.customer_name,
            phone: customer.phone ?? '',
            email: customer.email ?? '',
            address: customer.address ?? '',
            credit_limit: customer.credit_limit != null ? String(customer.credit_limit) : '',
            terms_of_payment_id: customer.terms_of_payment_id ?? '',
            is_active: customer.is_active,
          }
        : emptyValues,
    )
  }, [open, customer, form])

  const mutation = useMutation({
    mutationFn: (values: CustomerFormValues) => {
      const payload = {
        ...values,
        phone: values.phone || null,
        email: values.email || null,
        address: values.address || null,
        credit_limit: values.credit_limit ? Number(values.credit_limit) : null,
        terms_of_payment_id: values.terms_of_payment_id || null,
      }
      return isEdit ? updateCustomer(customer.id, payload) : createCustomer(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      toast.success(isEdit ? 'Customer updated.' : 'Customer created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: CustomerFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Customer' : 'New Customer'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${customer.customer_code}.` : 'Add a new customer.'}
          </SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="customer_code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Customer Code</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. CUS001" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="customer_name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Customer Name</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Toko Bangunan Jaya" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="phone"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Phone</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="email"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Email</FormLabel>
                    <FormControl>
                      <Input type="email" placeholder="Optional" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="address"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Address</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="credit_limit"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Credit Limit</FormLabel>
                    <FormControl>
                      <Input type="number" min="0" placeholder="Leave blank for unlimited" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="terms_of_payment_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Default Terms of Payment</FormLabel>
                    <Select value={field.value || NO_TOP} onValueChange={(value) => field.onChange(value === NO_TOP ? '' : value)}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={termsOfPayment.isLoading ? 'Loading…' : 'No default'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_TOP}>No default</SelectItem>
                        {termsOfPayment.data?.map((top) => (
                          <SelectItem key={top.id} value={top.id}>
                            {top.name} ({top.code})
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
                {isEdit ? 'Save Changes' : 'Create Customer'}
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
