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
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchWarehousesLookup } from '../api/lookupsApi'
import { createSalesPerson, fetchNextSalesPersonCode, updateSalesPerson } from '../api/salesPersonApi'
import type { SalesPerson } from '../types'

const salesPersonFormSchema = z.object({
  code: z.string().min(1, 'Code is required').max(255),
  name: z.string().min(1, 'Name is required').max(255),
  phone: z.string().max(50).optional().or(z.literal('')),
  email: z.string().email('Enter a valid email address').optional().or(z.literal('')),
  warehouse_id: z.string().optional().or(z.literal('')),
  is_active: z.boolean(),
})

type SalesPersonFormValues = z.infer<typeof salesPersonFormSchema>

const emptyValues: SalesPersonFormValues = {
  code: '',
  name: '',
  phone: '',
  email: '',
  warehouse_id: '',
  is_active: true,
}

interface SalesPersonFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  salesPerson?: SalesPerson | null
}

export function SalesPersonFormDrawer({ open, onOpenChange, salesPerson }: SalesPersonFormDrawerProps) {
  const isEdit = !!salesPerson
  const queryClient = useQueryClient()
  const warehouses = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  /**
   * Suggested default only, not a lock — the field stays editable, same as Customer Code.
   * Can go stale under concurrent creates; SalesPersonService::create() re-consumes a fresh
   * number server-side regardless, and only falls back to it when code is submitted blank.
   */
  const nextCode = useQuery({
    queryKey: ['sales-persons', 'next-code'],
    queryFn: fetchNextSalesPersonCode,
    enabled: open && !isEdit,
  })

  const form = useForm<SalesPersonFormValues>({
    resolver: zodResolver(salesPersonFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      salesPerson
        ? {
            code: salesPerson.code,
            name: salesPerson.name,
            phone: salesPerson.phone ?? '',
            email: salesPerson.email ?? '',
            warehouse_id: salesPerson.warehouse_id ?? '',
            is_active: salesPerson.is_active,
          }
        : emptyValues,
    )
  }, [open, salesPerson, form])

  // Fills the suggestion in once it arrives — only if the user hasn't already typed something
  // over it (e.g. the query resolving after they started editing shouldn't clobber their input).
  useEffect(() => {
    if (!open || isEdit || !nextCode.data) return
    if (!form.getValues('code')) {
      form.setValue('code', nextCode.data)
    }
  }, [open, isEdit, nextCode.data, form])

  const mutation = useMutation({
    mutationFn: (values: SalesPersonFormValues) => {
      const payload = {
        ...values,
        phone: values.phone || null,
        email: values.email || null,
        warehouse_id: values.warehouse_id || null,
      }
      return isEdit ? updateSalesPerson(salesPerson.id, payload) : createSalesPerson(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-persons'] })
      toast.success(isEdit ? 'Sales person updated.' : 'Sales person created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: SalesPersonFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Sales Person' : 'New Sales Person'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${salesPerson.code}.` : 'Add a new sales person.'}
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
                      <Input
                        placeholder={!isEdit && nextCode.isLoading ? 'Generating…' : 'e.g. SP-0001'}
                        autoComplete="off"
                        {...field}
                      />
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
                      <Input placeholder="e.g. Budi Santoso" autoComplete="off" {...field} />
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
                name="warehouse_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Location / Area</FormLabel>
                    <SearchableSelect
                      options={warehouses.data?.map((warehouse) => ({ value: warehouse.id, label: warehouse.name })) ?? []}
                      value={field.value || undefined}
                      onChange={(value) => field.onChange(value ?? '')}
                      loading={warehouses.isLoading}
                      placeholder="No default"
                      aria-label="Location / Area"
                    />
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
                {isEdit ? 'Save Changes' : 'Create Sales Person'}
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
