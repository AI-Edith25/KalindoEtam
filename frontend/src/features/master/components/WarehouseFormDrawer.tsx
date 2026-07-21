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
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { createWarehouse, updateWarehouse } from '../api/warehouseApi'
import { fetchBranches } from '../api/lookupsApi'
import type { Warehouse } from '../types'

const warehouseFormSchema = z.object({
  branch_id: z.string().min(1, 'Branch is required'),
  name: z.string().min(1, 'Warehouse Name is required').max(255),
  code: z.string().min(1, 'Warehouse Code is required').max(255),
  warehouse_type: z.enum(['main', 'transit', 'return']),
})

type WarehouseFormValues = z.infer<typeof warehouseFormSchema>

const emptyValues: WarehouseFormValues = {
  branch_id: '',
  name: '',
  code: '',
  warehouse_type: 'main',
}

interface WarehouseFormDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  warehouse?: Warehouse | null
}

export function WarehouseFormDrawer({ open, onOpenChange, warehouse }: WarehouseFormDrawerProps) {
  const isEdit = !!warehouse
  const queryClient = useQueryClient()

  const form = useForm<WarehouseFormValues>({
    resolver: zodResolver(warehouseFormSchema),
    defaultValues: emptyValues,
  })

  useEffect(() => {
    if (!open) return

    form.reset(
      warehouse
        ? {
            branch_id: warehouse.branch_id,
            name: warehouse.name,
            code: warehouse.code,
            warehouse_type: warehouse.warehouse_type,
          }
        : emptyValues,
    )
  }, [open, warehouse, form])

  const branches = useQuery({ queryKey: ['branches'], queryFn: fetchBranches })

  const mutation = useMutation({
    mutationFn: (values: WarehouseFormValues) =>
      isEdit ? updateWarehouse(warehouse.id, values) : createWarehouse(values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouses'] })
      toast.success(isEdit ? 'Warehouse updated.' : 'Warehouse created.')
      onOpenChange(false)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const onSubmit = (values: WarehouseFormValues) => mutation.mutate(values)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Warehouse' : 'New Warehouse'}</SheetTitle>
          <SheetDescription>
            {isEdit ? `Update details for ${warehouse.code}.` : 'Add a new warehouse.'}
          </SheetDescription>
        </SheetHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-1 flex-col overflow-y-auto">
            <div className="flex flex-col gap-4 px-4">
              <FormField
                control={form.control}
                name="branch_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Branch</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={branches.isLoading ? 'Loading…' : 'Select branch'} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {branches.data?.map((branch) => (
                          <SelectItem key={branch.id} value={branch.id}>
                            {branch.name}
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
                name="code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Warehouse Code</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. WH001" autoComplete="off" {...field} />
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
                    <FormLabel>Warehouse Name</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Main Warehouse" autoComplete="off" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="warehouse_type"
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
                        <SelectItem value="main">Main</SelectItem>
                        <SelectItem value="transit">Transit</SelectItem>
                        <SelectItem value="return">Return</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <SheetFooter>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
                {isEdit ? 'Save Changes' : 'Create Warehouse'}
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
