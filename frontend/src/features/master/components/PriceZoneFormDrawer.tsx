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
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { toastApiError } from '@/shared/services/errorHandler'
import { createPriceZone, updatePriceZone } from '../api/priceZoneApi'
import type { PriceZone } from '../types'

const priceZoneFormSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  description: z.string().max(255).optional().or(z.literal('')),
})

type PriceZoneFormValues = z.infer<typeof priceZoneFormSchema>

const emptyValues: PriceZoneFormValues = { name: '', description: '' }

interface PriceZoneFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  priceZone?: PriceZone | null
}

export function PriceZoneFormDrawer({ open, onOpenChange, priceZone }: PriceZoneFormDrawerProps) {
  const isEdit = !!priceZone
  const queryClient = useQueryClient()

  const form = useForm<PriceZoneFormValues>({
    resolver: zodResolver(priceZoneFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return
    form.reset(priceZone ? { name: priceZone.name, description: priceZone.description ?? '' } : emptyValues)
  }, [open, priceZone, form])

  const mutation = useMutation({
    mutationFn: (values: PriceZoneFormValues) => {
      const payload = { ...values, description: values.description || null }
      return isEdit ? updatePriceZone(priceZone.id, payload) : createPriceZone(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['price-zones-paged'] })
      toast.success(isEdit ? 'Price Zone updated.' : 'Price Zone created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: PriceZoneFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Price Zone' : 'New Price Zone'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${priceZone.name}.` : 'Add a new sales price zone.'}
          </SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Name</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Samarinda" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="description"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Description</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create Price Zone'}
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
