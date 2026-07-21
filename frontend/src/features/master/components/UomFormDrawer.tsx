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
import { getErrorMessage } from '@/shared/services/errorHandler'
import { createUom, updateUom } from '../api/uomApi'
import type { Uom } from '../types'

const uomFormSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  symbol: z.string().max(50).optional().or(z.literal('')),
})

type UomFormValues = z.infer<typeof uomFormSchema>

const emptyValues: UomFormValues = { name: '', symbol: '' }

interface UomFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  uom?: Uom | null
}

export function UomFormDrawer({ open, onOpenChange, uom }: UomFormDrawerProps) {
  const isEdit = !!uom
  const queryClient = useQueryClient()

  const form = useForm<UomFormValues>({
    resolver: zodResolver(uomFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return
    form.reset(uom ? { name: uom.name, symbol: uom.symbol ?? '' } : emptyValues)
  }, [open, uom, form])

  const mutation = useMutation({
    mutationFn: (values: UomFormValues) => {
      const payload = { ...values, symbol: values.symbol || null }
      return isEdit ? updateUom(uom.id, payload) : createUom(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['uoms'] })
      toast.success(isEdit ? 'UOM updated.' : 'UOM created.')
      onOpenChange(false)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const onSubmit = (values: UomFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit UOM' : 'New UOM'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${uom.name}.` : 'Add a new unit of measurement.'}
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
                      <Input placeholder="e.g. Kilogram" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="symbol"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Symbol</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Kg" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create UOM'}
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
