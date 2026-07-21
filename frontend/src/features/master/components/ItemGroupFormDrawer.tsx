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
import { createItemGroup, updateItemGroup } from '../api/itemGroupApi'
import type { ItemGroup } from '../types'

const itemGroupFormSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  description: z.string().max(255).optional().or(z.literal('')),
})

type ItemGroupFormValues = z.infer<typeof itemGroupFormSchema>

const emptyValues: ItemGroupFormValues = { name: '', description: '' }

interface ItemGroupFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  itemGroup?: ItemGroup | null
}

export function ItemGroupFormDrawer({ open, onOpenChange, itemGroup }: ItemGroupFormDrawerProps) {
  const isEdit = !!itemGroup
  const queryClient = useQueryClient()

  const form = useForm<ItemGroupFormValues>({
    resolver: zodResolver(itemGroupFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return
    form.reset(itemGroup ? { name: itemGroup.name, description: itemGroup.description ?? '' } : emptyValues)
  }, [open, itemGroup, form])

  const mutation = useMutation({
    mutationFn: (values: ItemGroupFormValues) => {
      const payload = { ...values, description: values.description || null }
      return isEdit ? updateItemGroup(itemGroup.id, payload) : createItemGroup(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['item-groups'] })
      toast.success(isEdit ? 'Item Group updated.' : 'Item Group created.')
      onOpenChange(false)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const onSubmit = (values: ItemGroupFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Item Group' : 'New Item Group'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${itemGroup.name}.` : 'Add a new item group.'}
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
                      <Input placeholder="e.g. Semen" autoComplete="off" {...field} />
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
                {isEdit ? 'Save Changes' : 'Create Item Group'}
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
