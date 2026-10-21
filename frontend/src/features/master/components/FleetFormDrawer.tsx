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
import { createFleet, updateFleet } from '../api/fleetApi'
import type { Fleet } from '../types'

const fleetFormSchema = z.object({
  plat_number: z.string().min(1, 'Plat number is required').max(20),
  default_driver_name: z.string().max(255).optional().or(z.literal('')),
  is_active: z.boolean(),
})

type FleetFormValues = z.infer<typeof fleetFormSchema>

const emptyValues: FleetFormValues = { plat_number: '', default_driver_name: '', is_active: true }

interface FleetFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  fleet?: Fleet | null
}

export function FleetFormDrawer({ open, onOpenChange, fleet }: FleetFormDrawerProps) {
  const isEdit = !!fleet
  const queryClient = useQueryClient()

  const form = useForm<FleetFormValues>({
    resolver: zodResolver(fleetFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      fleet
        ? { plat_number: fleet.plat_number, default_driver_name: fleet.default_driver_name ?? '', is_active: fleet.is_active }
        : emptyValues,
    )
  }, [open, fleet, form])

  const mutation = useMutation({
    mutationFn: (values: FleetFormValues) => {
      const payload = { ...values, default_driver_name: values.default_driver_name || null }
      return isEdit ? updateFleet(fleet.id, payload) : createFleet(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fleets'] })
      toast.success(isEdit ? 'Fleet updated.' : 'Fleet created.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  const onSubmit = (values: FleetFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Fleet' : 'New Fleet'}</SheetTitle>
          <SheetDescription>{isEdit ? `Update details for ${fleet.plat_number}.` : 'Add a new fleet (armada).'}</SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="plat_number"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Plat Number</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. KT 1234 AB" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="default_driver_name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Default Driver</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" autoComplete="off" {...field} />
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
                {isEdit ? 'Save Changes' : 'Create Fleet'}
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
